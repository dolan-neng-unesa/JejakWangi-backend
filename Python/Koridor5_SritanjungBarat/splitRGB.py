import cv2
import numpy as np
 
def split_to_RGB(mirror=False): #mirror digunakan untuk camera laptop
 
    cap = cv2.VideoCapture("highway.mp4")
    cv2.namedWindow('Split RGB',cv2.WINDOW_NORMAL)
    zeros = None
    while True:
        ret, frame = cap.read()
 
        if ret == True:
          
            height, width, layers = frame.shape
            matrix0 = np.zeros((height, width), dtype="uint8")
 
          
            (B, G, R) = cv2.split(frame)
 
          
            B = cv2.merge([B, matrix0, matrix0])
            G = cv2.merge([matrix0, G, matrix0])
            R = cv2.merge([matrix0, matrix0, R])
          
            final = np.zeros((height * 2, width * 2, 3), dtype="uint8")
 
            final[0:height, 0:width] = frame 
            final[0:height, width:width * 2] = B 
            final[height:height * 2, 0:width] = G  
            final[height:height * 2, width:width * 2] = R  
 
            cv2.imshow('Split RGB', final)
        else:
            break
 
        if cv2.waitKey(1) & 0xFF == ord('q'):  
            break
    cap.release()
    cv2.destroyAllWindows()
 
def main():
    split_to_RGB(mirror=True)
 
if __name__ == '__main__':
    main()