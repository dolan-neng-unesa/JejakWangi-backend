import numpy as np
import cv2
cap = cv2.VideoCapture('highway.mp4')
fgbg = cv2.createBackgroundSubtractorMOG2(detectShadows = True)
while(cap.isOpened()):
 ret, frame = cap.read()
 fgmask = fgbg.apply(frame)
 try :
  cv2.imshow('Frame',frame)
  cv2.imshow('Background Subtraction',fgmask)
  frame2 = frame
 except:
  print('EOF')
  break
 
 line1 = np.array([[200,400],[700,400],[1000,400]],np.int32).reshape((-1,1,2))
 line2 = np.array([[50,500],[1200,500]],np.int32).reshape((-1,1,2))
 frame2 = cv2.polylines(frame2,[line1],False,(255,0,0),thickness=2)
 frame2 = cv2.polylines(frame2,[line2],False,(0,0,255),thickness=2)
 cv2.imshow('Frame 2',frame2)
 
 k = cv2.waitKey(30)& 0xff
 if k == 27:
  break
cap.release()
cv2.destroyAllWindows()