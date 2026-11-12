import cv2, time
video=cv2.VideoCapture("highway.mp4")
a=0
while True:
    a=a+1
    check, frame = video.read()
    
    if not check:
        break

    print(frame)
    cv2.imshow("capturing", frame)
    key=cv2.waitKey(1)

    if key == ord('q'):
        break

video.release()
cv2.destroyAllWindows()