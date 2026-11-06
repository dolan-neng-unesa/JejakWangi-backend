import { act, createElement } from "react";
import { createRoot } from "react-dom/client";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

globalThis.IS_REACT_ACT_ENVIRONMENT = true;

const json = (body, init = {}) =>
  new Response(JSON.stringify(body), { status: 200, headers: { "Content-Type": "application/json" }, ...init });

const mounted = [];
async function render(el) {
  const root = createRoot(document.createElement("div"));
  mounted.push(root);
  await act(async () => root.render(el));
}

beforeEach(() => {
  localStorage.clear();
  vi.resetModules();
});

afterEach(async () => {
  while (mounted.length) await act(async () => mounted.pop().unmount());
  vi.useRealTimers();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  Object.defineProperty(document, "hidden", { configurable: true, get: () => false });
});

describe("loadKoridorSnapshots", () => {
  const koridors = [{ id: 1 }, { id: 2 }, { id: 3 }];

  it("memakai SATU request massal dan mengisi koridor tanpa bacaan dengan snapshot kosong", async () => {
    const fetchMock = vi.fn(async () => json({ snapshots: { 1: { koridor_id: "1", is_stale: false, readings: { pm25: { value: 10 } } } } }));
    vi.stubGlobal("fetch", fetchMock);
    const { loadKoridorSnapshots } = await import("../services/svarga");

    const snaps = await loadKoridorSnapshots(koridors);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(String(fetchMock.mock.calls[0][0])).toContain("/sensors/snapshots");
    expect(snaps[1].readings.pm25.value).toBe(10);
    expect(snaps[2]).toMatchObject({ is_stale: true, readings: {} });
  });

  it("backend lama (404) jatuh ke mode per-koridor", async () => {
    const fetchMock = vi.fn(async (url) =>
      String(url).includes("/sensors/snapshots")
        ? json({ message: "Data tidak ditemukan." }, { status: 404 })
        : json({ koridor_id: "x", is_stale: false, readings: {} })
    );
    vi.stubGlobal("fetch", fetchMock);
    const { loadKoridorSnapshots } = await import("../services/svarga");

    const snaps = await loadKoridorSnapshots(koridors);
    expect(fetchMock).toHaveBeenCalledTimes(1 + koridors.length);
    expect(Object.keys(snaps)).toHaveLength(3);
  });

  it("server error (500) TIDAK melipatgandakan request; semua koridor jadi baseline (null)", async () => {
    const fetchMock = vi.fn(async () => json({ message: "boom" }, { status: 500 }));
    vi.stubGlobal("fetch", fetchMock);
    const { loadKoridorSnapshots } = await import("../services/svarga");

    const snaps = await loadKoridorSnapshots(koridors);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(snaps).toEqual({ 1: null, 2: null, 3: null });
  });
});

describe("lib/api", () => {
  it("429 menampilkan pesan Indonesia dengan hitung mundur dari Retry-After", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => json({ message: "Too Many Attempts." }, { status: 429, headers: { "Retry-After": "45", "Content-Type": "application/json" } })));
    const { api } = await import("../lib/api");
    const err = await api.post("/auth/login", { email: "a" }, { auth: false }).catch((e) => e);
    expect(err.status).toBe(429);
    expect(err.message).toContain("45 detik");
  });

  it("timeout membatalkan request yang menggantung", async () => {
    vi.stubGlobal("fetch", vi.fn((_u, { signal }) => new Promise((_, rej) => signal.addEventListener("abort", () => rej(new DOMException("x", "AbortError"))))));
    const { request } = await import("../lib/api").then((m) => ({ request: (p) => m.api.get(p, undefined, {}) }));
    vi.useFakeTimers();
    const pending = request("/x").catch((e) => e);
    await vi.advanceTimersByTimeAsync(16_000);
    const err = await pending;
    expect(err.message).toMatch(/terlalu lama/i);
    expect(err.isNetwork).toBe(true);
  });
});

describe("useAsync polling", () => {
  function setHidden(hidden) {
    Object.defineProperty(document, "hidden", { configurable: true, get: () => hidden });
    document.dispatchEvent(new Event("visibilitychange"));
  }
  async function mountPoller(fn, interval) {
    const { useAsync } = await import("../hooks/useAsync");
    function C() { useAsync(fn, [], { interval }); return null; }
    await render(createElement(C));
  }

  it("melewati tick saat tab tersembunyi dan menyegarkan begitu terlihat lagi", async () => {
    vi.useFakeTimers();
    const fn = vi.fn(async () => 1);
    await mountPoller(fn, 1000);
    expect(fn).toHaveBeenCalledTimes(1);

    setHidden(true);
    await act(async () => vi.advanceTimersByTimeAsync(5000));
    expect(fn).toHaveBeenCalledTimes(1);

    await act(async () => setHidden(false));
    expect(fn).toHaveBeenCalledTimes(2);
  });

  it("tidak menumpuk request saat server lambat", async () => {
    vi.useFakeTimers();
    let inFlight = 0, max = 0;
    const fn = vi.fn(async () => {
      max = Math.max(max, ++inFlight);
      await new Promise((r) => setTimeout(r, 3500));
      inFlight--;
    });
    await mountPoller(fn, 1000);
    await act(async () => vi.advanceTimersByTimeAsync(10_000));
    expect(max).toBe(1);
  });
});

describe("AuthContext boot", () => {
  async function bootWith(fetchImpl) {
    vi.stubGlobal("fetch", vi.fn(fetchImpl));
    const api = await import("../lib/api");
    api.setToken("tok");
    const { AuthProvider, useAuth } = await import("../context/AuthContext");
    const ref = { current: null };
    function Probe() { ref.current = useAuth(); return null; }
    vi.useFakeTimers();
    await render(createElement(AuthProvider, null, createElement(Probe)));
    await act(async () => vi.advanceTimersByTimeAsync(10_000));
    return { ref, api };
  }

  it("server mati/jaringan putus TIDAK menghapus token (admin tidak ter-logout)", async () => {
    const { ref, api } = await bootWith(async () => { throw new TypeError("Failed to fetch"); });
    expect(ref.current.booting).toBe(false);
    expect(api.getToken()).toBe("tok");
  });

  it("401 menghapus token", async () => {
    const { ref, api } = await bootWith(async () => json({ message: "Unauthenticated." }, { status: 401 }));
    expect(ref.current.user).toBeNull();
    expect(api.getToken()).toBeFalsy();
  });

  it("berhasil pada percobaan ulang setelah server sempat belum siap", async () => {
    let n = 0;
    const { ref } = await bootWith(async () => {
      if (++n < 3) throw new TypeError("Failed to fetch");
      return json({ data: { id: 1, name: "Admin", email: "a@x.id", is_admin: true } });
    });
    expect(ref.current.user?.is_admin).toBe(true);
  });
});

describe("staticCache", () => {
  it("memakai ulang hasil dalam TTL, dan invalidateStatic memaksa muat ulang", async () => {
    const { cachedStatic, invalidateStatic } = await import("../lib/staticCache");
    const loader = vi.fn(async () => [1]);
    await cachedStatic(loader); await cachedStatic(loader);
    expect(loader).toHaveBeenCalledTimes(1);
    invalidateStatic();
    await cachedStatic(loader);
    expect(loader).toHaveBeenCalledTimes(2);
  });

  it("galat tidak di-cache", async () => {
    const { cachedStatic } = await import("../lib/staticCache");
    const loader = vi.fn().mockRejectedValueOnce(new Error("x")).mockResolvedValueOnce([2]);
    await cachedStatic(loader).catch(() => {});
    await expect(cachedStatic(loader)).resolves.toEqual([2]);
  });
});
