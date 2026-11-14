import { act, createElement } from "react";
import { createRoot } from "react-dom/client";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

globalThis.IS_REACT_ACT_ENVIRONMENT = true;

const json = (body, status = 200, headers = {}) =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json", ...headers } });

const mounted = [];
let box;
async function render(el) {
  box = document.createElement("div");
  document.body.appendChild(box);
  const root = createRoot(box);
  mounted.push(root);
  await act(async () => root.render(el));
}
const klikBuat = () => act(async () => { box.querySelector("button").click(); });

beforeEach(() => { localStorage.clear(); vi.resetModules(); });
afterEach(async () => {
  while (mounted.length) await act(async () => mounted.pop().unmount());
  document.body.innerHTML = "";
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("AsistenLaporan", () => {
  it("memanggil endpoint admin dengan token dan menampilkan teks + label sumber", async () => {
    localStorage.setItem("jejak-wangi-admin:token", "adm-1");
    const fetchMock = vi.fn(async () => json({ data: { teks: "Temuan 1: 3 aktivitas.\nUsulan 1: tambah papan info.", sumber: "ai" } }));
    vi.stubGlobal("fetch", fetchMock);
    const { default: AsistenLaporan } = await import("../components/AsistenLaporan");
    await render(createElement(AsistenLaporan));
    await klikBuat();

    expect(String(fetchMock.mock.calls[0][0])).toContain("/admin/eco-coach/laporan");
    expect(fetchMock.mock.calls[0][1].method).toBe("POST");
    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe("Bearer adm-1");
    expect(box.querySelector("[data-testid=laporan-teks]").textContent).toContain("Temuan 1: 3 aktivitas.");
    expect(box.querySelector("[data-testid=laporan-sumber]").textContent).toContain("Dijelaskan AI");
  });

  it("jawaban template diberi label 'Ringkasan otomatis'", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => json({ data: { teks: "Ringkasan otomatis minggu ini (tanpa AI):", sumber: "template" } })));
    const { default: AsistenLaporan } = await import("../components/AsistenLaporan");
    await render(createElement(AsistenLaporan));
    await klikBuat();
    expect(box.querySelector("[data-testid=laporan-sumber]").textContent).toContain("Ringkasan otomatis");
  });

  it("429 → pesan ramah; galat lain → pesan umum; tidak ada teks laporan", async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(json({ message: "Too Many" }, 429, { "Retry-After": "10" }))
      .mockResolvedValueOnce(json({ message: "boom" }, 500));
    vi.stubGlobal("fetch", fetchMock);
    const { default: AsistenLaporan } = await import("../components/AsistenLaporan");
    await render(createElement(AsistenLaporan));
    await klikBuat();
    expect(box.querySelector("[role=alert]").textContent).toContain("Terlalu sering");
    await klikBuat();
    expect(box.querySelector("[role=alert]").textContent).toContain("belum bisa dihubungi");
    expect(box.querySelector("[data-testid=laporan-teks]")).toBeNull();
  });
});
