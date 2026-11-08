// Cache singkat untuk data referensi yang jarang berubah (koridor, green space, zona geofence).
// useEnvironment dipolling tiap beberapa detik untuk bacaan sensor; sebelumnya SETIAP polling
// ikut mengunduh ulang tiga daftar statis ini. Sekarang hanya snapshot sensor yang diulang.
//
// Mutasi yang mengubah data tersebut (mis. buat/ubah/hapus zona geofence) wajib memanggil
// invalidateStatic() supaya halaman lain tidak menampilkan data basi.

const TTL_MS = 60_000;
let entry = null;

export function cachedStatic(loader) {
  if (entry && Date.now() - entry.at < TTL_MS) return entry.promise;
  const promise = loader();
  entry = { at: Date.now(), promise };
  promise.catch(() => {
    if (entry?.promise === promise) entry = null; // galat tidak boleh di-cache
  });
  return promise;
}

export function invalidateStatic() {
  entry = null;
}
