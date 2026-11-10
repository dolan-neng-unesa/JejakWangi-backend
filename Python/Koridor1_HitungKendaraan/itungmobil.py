import math
import cv2
import torch
from collections import Counter, defaultdict
from ultralytics import YOLO

VIDEO_PATH = 'highway.mp4'
MODEL_PATH = 'yolov8m.pt'
IMG_SIZE = 960
CONF = 0.15
IOU = 0.6
CONTAIN_TH = 0.80
ROI_TOP = 0.45
MIN_FRAMES = 10
MIN_BOX_W = 25
IGNORE_WIDE = 0.35
MAX_GAP = 45
MATCH_DIST = 1.0

TRACKER_YAML = 'botsort_custom.yaml'
with open(TRACKER_YAML, 'w') as f:
    f.write(
        "tracker_type: botsort\n"
        "track_high_thresh: 0.25\n"
        "track_low_thresh: 0.10\n"
        "new_track_thresh: 0.30\n"
        "track_buffer: 90\n"
        "match_thresh: 0.80\n"
        "fuse_score: True\n"
        "gmc_method: sparseOptFlow\n"
        "proximity_thresh: 0.5\n"
        "appearance_thresh: 0.25\n"
        "with_reid: False\n"
    )

device = 0 if torch.cuda.is_available() else 'cpu'
use_half = device != 'cpu'
print('Memakai device:', device)

model = YOLO(MODEL_PATH)
cap = cv2.VideoCapture(VIDEO_PATH)
w = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
h = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
roi_y = int(h * ROI_TOP)

TARGET_CLASSES = [2, 3, 5, 7]
GROUP = {2: 'mobil', 3: 'motor', 5: 'truk', 7: 'truk'}
COLOR = {'mobil': (0, 255, 0), 'motor': (255, 0, 0), 'truk': (0, 165, 255)}

votes = defaultdict(Counter)
seen = defaultdict(int)
counted = set()
alias = {}
tracks = {}

WINDOW = 'Deteksi Kendaraan - YOLOv8'
cv2.namedWindow(WINDOW, cv2.WINDOW_NORMAL)
cv2.resizeWindow(WINDOW, 1280, 720)
font = cv2.FONT_HERSHEY_SIMPLEX


def remove_nested(dets):
    """Buang kotak yang hampir seluruhnya berada di dalam kotak lain."""
    keep = []
    for i, a in enumerate(dets):
        area_a = max(1, (a[2] - a[0]) * (a[3] - a[1]))
        nested = False
        for j, b in enumerate(dets):
            if i == j:
                continue
            area_b = max(1, (b[2] - b[0]) * (b[3] - b[1]))
            ix = max(0, min(a[2], b[2]) - max(a[0], b[0]))
            iy = max(0, min(a[3], b[3]) - max(a[1], b[1]))
            if (ix * iy) / area_a >= CONTAIN_TH and (
                    area_a < area_b or (area_a == area_b and a[6] < b[6])):
                nested = True
                break
        if not nested:
            keep.append(a)
    return keep


def resolve_id(raw_id, box, active, frame_no):
    """Ubah id mentah tracker menjadi id kanonik.
    ID baru yang muncul dekat ID lama yang baru hilang = kendaraan yang sama."""
    if raw_id in alias:
        return alias[raw_id]

    x1, y1, x2, y2 = box
    cx, cy = (x1 + x2) / 2, (y1 + y2) / 2
    size = max(x2 - x1, y2 - y1)

    best, best_d = None, 1e9
    for cid, t in tracks.items():
        if cid in active:
            continue
        gap = frame_no - t['frame']
        if gap <= 0 or gap > MAX_GAP:
            continue
        d = math.hypot(cx - t['cx'], cy - t['cy'])
        limit = MATCH_DIST * max(size, t['size']) + gap * 2
        if d < limit and d < best_d:
            best, best_d = cid, d

    alias[raw_id] = best if best is not None else raw_id
    return alias[raw_id]


frame_no = 0
while cap.isOpened():
    ret, frame = cap.read()
    if not ret:
        break
    frame_no += 1

    r = model.track(
        frame[roi_y:], persist=True, tracker=TRACKER_YAML,
        conf=CONF, iou=IOU, imgsz=IMG_SIZE,
        agnostic_nms=True, classes=TARGET_CLASSES, max_det=300,
        device=device, half=use_half, verbose=False
    )[0]

    dets = []
    if r.boxes.id is not None:
        for box, tid, cls, cf in zip(r.boxes.xyxy.cpu().numpy(),
                                     r.boxes.id.cpu().numpy(),
                                     r.boxes.cls.cpu().numpy(),
                                     r.boxes.conf.cpu().numpy()):
            x1, y1, x2, y2 = map(int, box)
            y1 += roi_y
            y2 += roi_y
            bw = x2 - x1
            if bw > w * IGNORE_WIDE or bw < MIN_BOX_W:
                continue
            dets.append((x1, y1, x2, y2, int(tid), GROUP[int(cls)], float(cf)))

    dets = remove_nested(dets)

    active = {alias[d[4]] for d in dets if d[4] in alias}

    shown = 0
    for x1, y1, x2, y2, raw_id, group, cf in dets:
        cid = resolve_id(raw_id, (x1, y1, x2, y2), active, frame_no)
        if cid in active and alias.get(raw_id) != cid:
            continue
        active.add(cid)

        tracks[cid] = {'cx': (x1 + x2) / 2, 'cy': (y1 + y2) / 2,
                       'size': max(x2 - x1, y2 - y1), 'frame': frame_no}
        votes[cid][group] += 1
        seen[cid] += 1
        shown += 1

        if seen[cid] >= MIN_FRAMES:
            counted.add(cid)

        label = votes[cid].most_common(1)[0][0]
        color = COLOR[label]
        thick = 3 if cid in counted else 1
        cv2.rectangle(frame, (x1, y1), (x2, y2), color, thick)
        cv2.putText(frame, f'{label} {cid}', (x1, y1 - 6), font, 0.45, color, 1, cv2.LINE_AA)

    for cid in [c for c, t in tracks.items() if frame_no - t['frame'] > MAX_GAP]:
        del tracks[cid]

    final = [votes[t].most_common(1)[0][0] for t in counted]
    cv2.putText(frame, f"Jumlah Mobil: {final.count('mobil')}", (15, 40), font, 0.7, COLOR['mobil'], 2, cv2.LINE_AA)
    cv2.putText(frame, f"Jumlah Motor: {final.count('motor')}", (15, 75), font, 0.7, COLOR['motor'], 2, cv2.LINE_AA)
    cv2.putText(frame, f"Jumlah Truk: {final.count('truk')}", (15, 110), font, 0.7, COLOR['truk'], 2, cv2.LINE_AA)
    cv2.putText(frame, f"Terdeteksi di layar: {shown}", (15, 145), font, 0.6, (255, 255, 255), 2, cv2.LINE_AA)

    cv2.imshow(WINDOW, frame)
    if cv2.waitKey(1) & 0xff == 27:
        break

cap.release()
cv2.destroyAllWindows()
final = [votes[t].most_common(1)[0][0] for t in counted]
print('Total:', {g: final.count(g) for g in COLOR})