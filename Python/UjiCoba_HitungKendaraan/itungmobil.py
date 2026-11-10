import cv2
import numpy as np
from ultralytics import YOLO

model = YOLO('yolov8m.pt')

cap = cv2.VideoCapture('highway.mp4')

w = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
h = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))

line_y = int(h * 0.65)

TARGET_CLASSES = [2, 3, 5, 7] 

counted_car_ids = set()
counted_motor_ids = set()
counted_truck_ids = set()

font = cv2.FONT_HERSHEY_SIMPLEX

WINDOW = 'Deteksi Kendaraan - YOLOv8'
cv2.namedWindow(WINDOW, cv2.WINDOW_NORMAL)

cv2.resizeWindow(WINDOW, 1280, 720)

while cap.isOpened():
    ret, frame = cap.read()
    if not ret:
        break

    results = model.track(
        frame, 
        persist=True, 
        conf=0.10, 
        iou=0.8, 
        imgsz=1280, 
        agnostic_nms=True,
        classes=TARGET_CLASSES, 
        verbose=False
    )
    
    if results[0].boxes.id is not None:
        boxes = results[0].boxes.xyxy.cpu().numpy()
        track_ids = results[0].boxes.id.cpu().numpy()
        classes = results[0].boxes.cls.cpu().numpy()

        for box, track_id, cls in zip(boxes, track_ids, classes):
            x1, y1, x2, y2 = map(int, box)
            cx = int((x1 + x2) / 2)
            cy = int((y1 + y2) / 2)

            if cls == 2: # Mobil
                label_name = f"Mobil ID:{int(track_id)}"
                color = (0, 255, 0)
                if y2 >= line_y - 80: 
                    if track_id not in counted_car_ids:
                        counted_car_ids.add(track_id)
                        
            elif cls == 3: # Motor
                label_name = f"Motor ID:{int(track_id)}"
                color = (255, 0, 0)
                if y2 >= line_y - 80:
                    if track_id not in counted_motor_ids:
                        counted_motor_ids.add(track_id)
                        
            elif cls in [5, 7]: # Truk (dan Bus)
                label_name = f"Truk ID:{int(track_id)}"
                color = (0, 165, 255)
                if y2 >= line_y - 80:
                    if track_id not in counted_truck_ids:
                        counted_truck_ids.add(track_id)

            cv2.rectangle(frame, (x1, y1), (x2, y2), color, 2)
            cv2.circle(frame, (cx, cy), 4, (0, 0, 255), -1)
            cv2.putText(frame, label_name, (x1, y1 - 10), font, 0.4, color, 1, cv2.LINE_AA)

    cv2.line(frame, (0, line_y), (w, line_y), (0, 255, 255), 2)

    str_car = f'Jumlah Mobil: {len(counted_car_ids)}'
    str_motor = f'Jumlah Motor: {len(counted_motor_ids)}'
    str_truck = f'Jumlah Truk: {len(counted_truck_ids)}'
    
    cv2.putText(frame, str_car, (15, 40), font, 0.7, (0, 255, 0), 2, cv2.LINE_AA)
    cv2.putText(frame, str_motor, (15, 75), font, 0.7, (255, 0, 0), 2, cv2.LINE_AA)
    cv2.putText(frame, str_truck, (15, 110), font, 0.7, (0, 165, 255), 2, cv2.LINE_AA)

    cv2.imshow(WINDOW, frame)

    k = cv2.waitKey(30) & 0xff
    if k == 27:
        break

cap.release()
cv2.destroyAllWindows()