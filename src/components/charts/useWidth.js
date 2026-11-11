import { useEffect, useRef, useState } from "react";

/** Lebar elemen (px) — supaya SVG dirender tajam tanpa distorsi teks. */
export function useWidth(initial = 600) {
  const ref = useRef(null);
  const [w, setW] = useState(initial);
  useEffect(() => {
    const el = ref.current;
    if (!el) return undefined;
    const ro = new ResizeObserver(([e]) => setW(Math.max(200, Math.floor(e.contentRect.width))));
    ro.observe(el);
    setW(Math.max(200, el.clientWidth || initial));
    return () => ro.disconnect();
  }, [initial]);
  return [ref, w];
}
