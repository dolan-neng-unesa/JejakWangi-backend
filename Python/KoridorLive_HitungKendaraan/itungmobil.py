import os

os.environ['OPENCV_FFMPEG_LOGLEVEL'] = '-8'
os.environ['OPENCV_LOG_LEVEL'] = 'SILENT'

import math
import time
import threading
from collections import deque, Counter 

import cv2
import yt_dlp
from ultralytics import YOLO

try:
    import torch
    USE_GPU = torch.cuda.is_available()
except Exception:
    USE_GPU = False

YOUTUBE_URL = 'https://www.youtube.com/watch?v=W0hB9CXqPAc'
MAX_HEIGHT = 720
MODEL_PATH = 'yolov8s.pt'
IMG_SIZE = 960
CONF = 0.25
BUFFER_FRAMES = 60

NEAR_MIN_SIZE_FRAC = 0.05
SHOW_FAR = True

GATE_FRAC = 2.0
GATE_MIN_PX = 30
MAX_LOST_FRAMES = 60

MIN_HITS = 3
MOVE_MIN_PX = 25
MOVE_FRAC = 0.5
MAX_AREA_FRAC = 0.10

TARGET_CLASSES = [2, 3, 5, 7]
CLASS_INFO = {2: 'Mobil', 3: 'Motor', 5: 'Truk', 7: 'Truk'}
NAME_COLOR = {'Mobil': (0, 255, 0), 'Motor': (255, 0, 0), 'Truk': (0, 165, 255)}
NEAR_IDLE_COLOR = (200, 200, 200)
FAR_COLOR = (90, 90, 90)
WINDOW = 'Deteksi Kendaraan - YouTube Live'


def get_stream_url(youtube_url, max_height=720):
    ydl_opts = {
        'format': (
            f'bv*[height<={max_height}][protocol^=m3u8]/'
            f'bv*[height<={max_height}]/'
            f'b[height<={max_height}]/'
            'bv*/b'
        ),
        'quiet': True,
        'no_warnings': True,
    }
    with yt_dlp.YoutubeDL(ydl_opts) as ydl:
        info = ydl.extract_info(youtube_url, download=False)
        if info.get('url'):
            return info['url']
        for f in info.get('requested_formats', []):
            if f.get('vcodec') not in (None, 'none') and f.get('url'):
                return f['url']
        raise RuntimeError('Tidak ada URL video yang ditemukan')


class StreamBuffer:
    """Membaca stream di thread terpisah ke dalam buffer berisi (nomor_frame, frame)."""

    def __init__(self, youtube_url, max_height, buffer_frames):
        self.youtube_url = youtube_url
        self.max_height = max_height
        self.buffer_frames = buffer_frames
        self.buffer = deque()
        self.lock = threading.Lock()
        self.cap = None
        self.fps = 30.0
        self.counter = 0
        self.running = True
        self._connect()
        self.thread = threading.Thread(target=self._loop, daemon=True)
        self.thread.start()

    def _connect(self):
        if self.cap is not None:
            self.cap.release()
            self.cap = None
        try:
            print('Menyambungkan ke stream...')
            url = get_stream_url(self.youtube_url, self.max_height)
            cap = cv2.VideoCapture(url)
            if cap.isOpened():
                fps = cap.get(cv2.CAP_PROP_FPS)
                self.fps = fps if 5 <= fps <= 60 else 30.0
                self.cap = cap
                print(f'Stream tersambung ({self.fps:.0f} FPS).')
                return True
            cap.release()
            print('OpenCV gagal membuka URL stream.')
        except Exception as e:
            print(f'Gagal mengambil stream: {e}')
        return False

    def _loop(self):
        fail = 0
        while self.running:
            if self.cap is None or not self.cap.isOpened():
                if not self._connect():
                    time.sleep(5)
                continue

            with self.lock:
                full = len(self.buffer) >= self.buffer_frames
            if full:
                time.sleep(0.005)
                continue

            ret, frame = self.cap.read()
            if not ret:
                fail += 1
                if fail > 30:
                    print('Stream terputus, mencoba tersambung ulang...')
                    self._connect()
                    fail = 0
                time.sleep(0.1)
                continue

            fail = 0
            with self.lock:
                self.counter += 1
                self.buffer.append((self.counter, frame))

    def get(self):
        """Ambil frame tertua (untuk ditampilkan). Mengembalikan (nomor, frame) atau None."""
        with self.lock:
            return self.buffer.popleft() if self.buffer else None

    def newest(self):
        """Lihat frame terbaru (untuk dideteksi) tanpa mengeluarkannya dari buffer."""
        with self.lock:
            return self.buffer[-1] if self.buffer else None

    def size(self):
        with self.lock:
            return len(self.buffer)

    def stop(self):
        self.running = False
        self.thread.join(timeout=2)
        if self.cap is not None:
            self.cap.release()


class CountingTracker:
    """Pelacak sederhana berbasis jarak + prediksi kecepatan.

    Dibuat khusus karena deteksi di CPU hanya ~5 FPS: kendaraan dekat bisa berpindah
    lebih dari satu lebar badannya di antara dua deteksi, sehingga pelacak berbasis
    tumpang-tindih kotak (IoU) kehilangan jejak. Di sini pencocokan memakai jarak ke
    posisi PREDIKSI, dengan batas jarak sebanding ukuran kendaraan.

    Sebuah kendaraan dihitung SEKALI jika: sudah terdeteksi >= MIN_HITS kali, sedang
    berada di zona DEKAT (ukuran kotak besar), dan terbukti bergerak (berpindah cukup
    jauh dari posisi awalnya). Kendaraan jauh dan yang parkir tidak dihitung.
    """

    def __init__(self, frame_w):
        self.near_min = NEAR_MIN_SIZE_FRAC * frame_w
        self.tracks = {}
        self.counted = []
        self.next_id = 1

    @staticmethod
    def _majority(state):
        return state['votes'].most_common(1)[0][0]

    def counts(self):
        c = {'Mobil': 0, 'Motor': 0, 'Truk': 0}
        for s in self.counted:
            n = self._majority(s)
            c[n] = c.get(n, 0) + 1
        return c

    def _new_track(self, idx, it):
        tid = self.next_id
        self.next_id += 1
        self.tracks[tid] = {
            'cx': it['cx'], 'cy': it['cy'], 'size': it['size'], 'bw': it['bw'],
            'ox': it['cx'], 'oy': it['cy'],
            'vx': 0.0, 'vy': 0.0,
            'last_idx': idx, 'hits': 1,
            'votes': Counter([it['name']]),
            'counted': False,
        }
        return tid

    def _update_track(self, s, idx, it):
        dt = max(idx - s['last_idx'], 1)
        nvx = (it['cx'] - s['cx']) / dt
        nvy = (it['cy'] - s['cy']) / dt
        if s['hits'] == 1:
            s['vx'], s['vy'] = nvx, nvy
        else:
            s['vx'] = 0.6 * nvx + 0.4 * s['vx']
            s['vy'] = 0.6 * nvy + 0.4 * s['vy']
        s['cx'], s['cy'] = it['cx'], it['cy']
        s['size'], s['bw'] = it['size'], it['bw']
        s['last_idx'] = idx
        s['hits'] += 1
        s['votes'][it['name']] += 1

        if (not s['counted'] and s['hits'] >= MIN_HITS
                and s['size'] >= self.near_min):
            dist = math.hypot(s['cx'] - s['ox'], s['cy'] - s['oy'])
            if dist >= max(MOVE_MIN_PX, MOVE_FRAC * s['bw']):
                s['counted'] = True
                self.counted.append(s)

    def update(self, idx, dets):
        """dets: list (nama, x1, y1, x2, y2). Mengembalikan list
        (id, nama, x1, y1, x2, y2, sudah_dihitung, dekat)."""
        items = []
        for name, x1, y1, x2, y2 in dets:
            bw, bh = x2 - x1, y2 - y1
            items.append({
                'name': name, 'box': (x1, y1, x2, y2),
                'cx': (x1 + x2) / 2.0, 'cy': (y1 + y2) / 2.0,
                'bw': bw, 'size': math.sqrt(max(bw * bh, 1)),
            })

        for tid in [t for t, s in self.tracks.items()
                    if idx - s['last_idx'] > MAX_LOST_FRAMES]:
            del self.tracks[tid]

        pairs = []
        for tid, s in self.tracks.items():
            dt = min(idx - s['last_idx'], 30)
            px = s['cx'] + s['vx'] * dt
            py = s['cy'] + s['vy'] * dt
            for j, it in enumerate(items):
                ratio = it['size'] / s['size']
                if ratio < 0.5 or ratio > 2.0:
                    continue
                limit = max(GATE_MIN_PX, GATE_FRAC * (s['size'] + it['size']) / 2.0)
                d = math.hypot(it['cx'] - px, it['cy'] - py)
                if d <= limit:
                    pairs.append((d / limit, tid, j))
        pairs.sort()

        used_t, used_d = set(), set()
        for _, tid, j in pairs:
            if tid in used_t or j in used_d:
                continue
            used_t.add(tid)
            used_d.add(j)
            self._update_track(self.tracks[tid], idx, items[j])
            items[j]['tid'] = tid

        for j, it in enumerate(items):
            if j not in used_d:
                it['tid'] = self._new_track(idx, it)

        out = []
        for it in items:
            s = self.tracks[it['tid']]
            x1, y1, x2, y2 = it['box']
            out.append((it['tid'], self._majority(s), x1, y1, x2, y2,
                        s['counted'], it['size'] >= self.near_min))
        return out


class Detector(threading.Thread):
    """Mendeteksi kendaraan pada frame terbaru di buffer (mendahului tampilan),
    lalu melacak dan menghitungnya. Hasil disimpan per nomor frame."""

    def __init__(self, model, stream, frame_w, frame_h):
        super().__init__(daemon=True)
        self.model = model
        self.stream = stream
        self.frame_area = frame_w * frame_h
        self.tracker = CountingTracker(frame_w)
        self.lock = threading.Lock()
        self.results = deque(maxlen=400)
        self.infer_fps = 0.0
        self.running = True

    def bracket(self, d):
        """Cari dua hasil deteksi yang mengapit nomor frame d."""
        with self.lock:
            res = list(self.results)
        p0 = p1 = None
        for e in res:
            if e[0] <= d:
                p0 = e
            else:
                p1 = e
                break
        return p0, p1

    def run(self):
        last_idx = -1
        while self.running:
            item = self.stream.newest()
            if item is None or item[0] == last_idx:
                time.sleep(0.005)
                continue
            idx, frame = item
            last_idx = idx

            t0 = time.perf_counter()
            results = self.model.predict(
                frame,
                conf=CONF,
                iou=0.7,
                imgsz=IMG_SIZE,
                agnostic_nms=True,
                classes=TARGET_CLASSES,
                half=USE_GPU,
                verbose=False,
            )

            dets = []
            r = results[0]
            if r.boxes is not None and len(r.boxes) > 0:
                boxes = r.boxes.xyxy.cpu().numpy()
                clss = r.boxes.cls.cpu().numpy().astype(int)
                for box, cls in zip(boxes, clss):
                    x1, y1, x2, y2 = map(int, box)
                    if (x2 - x1) * (y2 - y1) > MAX_AREA_FRAC * self.frame_area:
                        continue
                    dets.append((CLASS_INFO.get(cls, 'Kendaraan'), x1, y1, x2, y2))

            out = self.tracker.update(idx, dets)
            counts = self.tracker.counts()

            dt = time.perf_counter() - t0
            fps = 1.0 / dt if dt > 0 else 0.0
            self.infer_fps = fps if self.infer_fps == 0 else 0.9 * self.infer_fps + 0.1 * fps
            with self.lock:
                self.results.append((idx, out, counts))


def interpolate(p0, p1, d):
    """Hitung posisi kotak pada frame d dengan interpolasi linear antara dua hasil deteksi."""
    if p0 is None:
        return [], None
    if p1 is None:
        return p0[1], p0[2]

    a = (d - p0[0]) / float(p1[0] - p0[0])
    m0 = {x[0]: x for x in p0[1]}
    m1 = {x[0]: x for x in p1[1]}
    out = []

    for tid, x in m0.items():
        if tid in m1:
            y = m1[tid]
            box = tuple(int(x[i] + (y[i] - x[i]) * a) for i in (2, 3, 4, 5))
            src = y if a >= 0.5 else x
            out.append((tid, src[1], *box, src[6], src[7]))
        elif a < 0.5:
            out.append(x)
    for tid, y in m1.items():
        if tid not in m0 and a >= 0.5:
            out.append(y)

    return out, (p1[2] if a >= 0.5 else p0[2])


def draw_overlay(frame, dets, counts, infer_fps, buf_size, near_min_px):
    font = cv2.FONT_HERSHEY_SIMPLEX
    h, w = frame.shape[:2]

    for (tid, name, x1, y1, x2, y2, counted, near) in dets:
        if counted:
            color = NAME_COLOR.get(name, (255, 255, 255))
            cv2.rectangle(frame, (x1, y1), (x2, y2), color, 2)
            cv2.putText(frame, f'{name} {tid}', (x1, max(12, y1 - 6)), font, 0.45,
                        color, 1, cv2.LINE_AA)
        elif near:
            cv2.rectangle(frame, (x1, y1), (x2, y2), NEAR_IDLE_COLOR, 1)
        elif SHOW_FAR:
            cv2.rectangle(frame, (x1, y1), (x2, y2), FAR_COLOR, 1)

    counts = counts or {}
    cv2.putText(frame, f"Jumlah Mobil: {counts.get('Mobil', 0)}", (15, 40),
                font, 0.7, (0, 255, 0), 2, cv2.LINE_AA)
    cv2.putText(frame, f"Jumlah Motor: {counts.get('Motor', 0)}", (15, 75),
                font, 0.7, (255, 0, 0), 2, cv2.LINE_AA)
    cv2.putText(frame, f"Jumlah Truk: {counts.get('Truk', 0)}", (15, 110),
                font, 0.7, (0, 165, 255), 2, cv2.LINE_AA)

    device = 'GPU' if USE_GPU else 'CPU'
    cv2.putText(frame, 'berwarna = dihitung | putih = dekat, belum bergerak/parkir | '
                       'gelap = jauh (tidak dihitung)',
                (15, h - 38), font, 0.45, (255, 255, 255), 1, cv2.LINE_AA)
    cv2.putText(frame, f'Deteksi: {infer_fps:.1f} FPS ({device}) | Buffer: {buf_size}',
                (15, h - 15), font, 0.5, (255, 255, 255), 1, cv2.LINE_AA)

    s = int(near_min_px)
    cv2.rectangle(frame, (w - 15 - s, h - 15 - s), (w - 15, h - 15), (255, 255, 255), 1)
    cv2.putText(frame, 'ukuran min dihitung', (w - 15 - s - 5, h - 20 - s), font, 0.4,
                (255, 255, 255), 1, cv2.LINE_AA)


def main():
    print(f"Perangkat: {'GPU (CUDA)' if USE_GPU else 'CPU'}")
    model = YOLO(MODEL_PATH)
    stream = StreamBuffer(YOUTUBE_URL, MAX_HEIGHT, BUFFER_FRAMES)
    detector = None

    print('Mengisi buffer... (Ctrl+C untuk berhenti)')
    try:
        while stream.size() < min(BUFFER_FRAMES, 20):
            time.sleep(0.1)

        last_idx, last = stream.get()
        h, w = last.shape[:2]
        near_min_px = NEAR_MIN_SIZE_FRAC * w

        detector = Detector(model, stream, w, h)
        detector.start()

        cv2.namedWindow(WINDOW)
        print("Berjalan. Kotak muncul beberapa detik setelah mulai. "
              "Tekan 'q' atau ESC untuk keluar.")

        interval = 1.0 / stream.fps
        next_t = time.perf_counter()

        while True:
            item = stream.get()
            if item is not None:
                last_idx, last = item

            p0, p1 = detector.bracket(last_idx)
            dets, counts = interpolate(p0, p1, last_idx)

            disp = last.copy()
            draw_overlay(disp, dets, counts, detector.infer_fps, stream.size(), near_min_px)
            cv2.imshow(WINDOW, disp)

            if cv2.waitKey(1) & 0xFF in (27, ord('q')):
                break

            next_t += interval
            delay = next_t - time.perf_counter()
            if delay > 0:
                time.sleep(delay)
            else:
                next_t = time.perf_counter()

    except KeyboardInterrupt:
        print('Dihentikan oleh pengguna.')
    finally:
        if detector is not None:
            detector.running = False
            detector.join(timeout=3)
            c = detector.tracker.counts()
            print(f"Total akhir -> Mobil: {c.get('Mobil', 0)}, "
                  f"Motor: {c.get('Motor', 0)}, Truk/Bus: {c.get('Truk', 0)}")
        stream.stop()
        cv2.destroyAllWindows()


if __name__ == '__main__':
    main()