// =====================================================
// JEJAK WANGI — Monitoring Kualitas Udara 5 Wilayah
// ESP32 + TFT ILI9341 + 3 tombol + 5 LED wilayah
// Kirim data berkala ke backend Laravel JEJAK WANGI
//   POST /api/sensors/readings   (Authorization: Bearer <DEVICE_TOKEN>)
//
// Board  : ESP32 Dev Module (Arduino core 2.x / 3.x)
// Library: Adafruit GFX, Adafruit ILI9341 (WiFi/HTTPClient bawaan core)
// Konfigurasi WiFi, server & token ada di config.h
//
// Letakkan jejakwangi.ino dan config.h dalam satu folder bernama "jejakwangi".
// =====================================================
#include <SPI.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <Adafruit_GFX.h>
#include <Adafruit_ILI9341.h>
#include "config.h"

// =====================================
// TFT ILI9341
// =====================================
#define TFT_CS   5
#define TFT_DC   2
#define TFT_RST  4

Adafruit_ILI9341 tft(TFT_CS, TFT_DC, TFT_RST);

// =====================================
// PIN TOMBOL
// =====================================
const int tombolKiri  = 32;
const int tombolOK    = 33;
const int tombolKanan = 21;

// =====================================
// PIN LED WILAYAH
// =====================================
const int ledPins[] = {13, 14, 25, 26, 27};
const int jumlahWilayah = 5;
const int jumlahMenu = jumlahWilayah + 1; // 5 wilayah + setting

bool statusLED[jumlahWilayah] = {
  false, false, false, false, false
};

// =====================================
// IDENTITAS PEMBUAT
// GANTI SESUAI IDENTITAS KAMU
// =====================================
const char* usernameInstagram = "@honorsending";
const char* namaPembuat = "@honorsending";

// =====================================
// DATA UDARA (SIMULASI)
// suhu °C, co ppm, co2 ppm, pm25 ug/m3, o2 %
// AQI dihitung dari PM2.5 (rumus sama dengan server)
// =====================================
struct DataUdara {
  float suhu;
  float co;
  int   co2;
  float pm25;
  float o2;
};

DataUdara dataUdara[jumlahWilayah] = {
  {31.0,  4.0, 480, 54.0, 20.8},
  {33.5, 12.0, 620, 72.0, 20.5},
  {29.0,  2.0, 430, 18.0, 20.9},
  {27.5,  0.8, 400,  8.0, 21.0},
  {28.5,  1.5, 415, 12.0, 20.9}
};

// Data dipakai 2 core (UI di core 1, jaringan di core 0) -> dilindungi mutex
SemaphoreHandle_t mutexData;

// =====================================
// STATUS JARINGAN (ditulis task jaringan, dibaca UI)
// =====================================
volatile bool wifiTerhubung = false;
volatile int  rssiTerakhir = 0;
volatile int  kodeHttpTerakhir = 0;     // 200/201 = sukses
volatile unsigned long jumlahKirimOK = 0;
volatile unsigned long jumlahKirimGagal = 0;
volatile bool kirimSegera = false;      // diset saat LED di-toggle
volatile bool statusJaringanBerubah = true;

// =====================================
// STATUS PROGRAM
// =====================================
int pilihan = 0;

bool halamanDetail = false;
bool halamanSetting = false;

bool okSebelumnya = HIGH;
bool okLongHandled = false;
bool okMulaiDiDetail = false;
bool okMulaiDiSetting = false;

bool kiriSebelumnya = HIGH;
bool kananSebelumnya = HIGH;

unsigned long waktuTekanOK = 0;
unsigned long debounceKiri = 0;
unsigned long debounceKanan = 0;
unsigned long waktuUpdateData = 0;
unsigned long waktuStatusUI = 0;

const unsigned long durasiTekanLama = 1000;
const unsigned long debounceDelay = 180;

// Deklarasi fungsi
void gambarMenu();
void gambarSetting();
void gambarDetail();
void updateAngkaDetail();
void gambarStatusJaringan();
void acakDataWilayah(int i);
int  hitungAQI(float pm25);
void taskJaringan(void* param);

// =====================================
// SETUP
// =====================================
void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println();
  Serial.println("JEJAK WANGI fw " FW_VERSION);

  mutexData = xSemaphoreCreateMutex();

  SPI.begin(18, 19, 23, TFT_CS);

  tft.begin();
  tft.setRotation(1);

  pinMode(tombolKiri, INPUT_PULLUP);
  pinMode(tombolOK, INPUT_PULLUP);
  pinMode(tombolKanan, INPUT_PULLUP);

  for (int i = 0; i < jumlahWilayah; i++) {
    pinMode(ledPins[i], OUTPUT);
    digitalWrite(ledPins[i], LOW);
  }

  randomSeed(esp_random());

  // Task jaringan di core 0 agar tombol & LCD (core 1) tidak macet saat kirim HTTPS
  xTaskCreatePinnedToCore(taskJaringan, "jaringan", 8192, NULL, 1, NULL, 0);

  gambarMenu();
}

// =====================================
// LOOP UTAMA (UI)
// =====================================
void loop() {
  unsigned long sekarang = millis();

  bool kiri  = digitalRead(tombolKiri);
  bool ok    = digitalRead(tombolOK);
  bool kanan = digitalRead(tombolKanan);

  // -------------------------------------
  // TOMBOL KIRI
  // -------------------------------------
  if (!halamanDetail &&
      !halamanSetting &&
      kiriSebelumnya == HIGH &&
      kiri == LOW &&
      sekarang - debounceKiri >= debounceDelay) {

    pilihan = (pilihan - 1 + jumlahMenu) % jumlahMenu;

    debounceKiri = sekarang;
    gambarMenu();
  }

  // -------------------------------------
  // TOMBOL KANAN
  // -------------------------------------
  if (!halamanDetail &&
      !halamanSetting &&
      kananSebelumnya == HIGH &&
      kanan == LOW &&
      sekarang - debounceKanan >= debounceDelay) {

    pilihan = (pilihan + 1) % jumlahMenu;

    debounceKanan = sekarang;
    gambarMenu();
  }

  // -------------------------------------
  // OK MULAI DITEKAN
  // -------------------------------------
  if (okSebelumnya == HIGH && ok == LOW) {
    waktuTekanOK = sekarang;
    okLongHandled = false;

    okMulaiDiDetail = halamanDetail;
    okMulaiDiSetting = halamanSetting;
  }

  // -------------------------------------
  // OK DITAHAN 1 DETIK -> HALAMAN DETAIL
  // HANYA UNTUK WILAYAH 1-5
  // -------------------------------------
  if (ok == LOW &&
      !okLongHandled &&
      !okMulaiDiDetail &&
      !okMulaiDiSetting &&
      pilihan < jumlahWilayah &&
      sekarang - waktuTekanOK >= durasiTekanLama) {

    okLongHandled = true;
    halamanDetail = true;

    gambarDetail();
  }

  // -------------------------------------
  // OK DILEPAS
  // -------------------------------------
  if (okSebelumnya == LOW && ok == HIGH) {

    if (!okLongHandled) {

      if (okMulaiDiDetail) {
        // Kembali dari detail
        halamanDetail = false;
        gambarMenu();

      } else if (okMulaiDiSetting) {
        // Kembali dari setting
        halamanSetting = false;
        gambarMenu();

      } else if (pilihan < jumlahWilayah) {
        // Klik singkat: ON/OFF wilayah. Hanya SATU wilayah yang boleh ON:
        // menyalakan wilayah baru otomatis mematikan wilayah lain.
        bool led[jumlahWilayah];
        xSemaphoreTake(mutexData, portMAX_DELAY);
        bool nyala = !statusLED[pilihan];
        for (int i = 0; i < jumlahWilayah; i++) statusLED[i] = false;
        statusLED[pilihan] = nyala;
        memcpy(led, statusLED, sizeof(led));
        xSemaphoreGive(mutexData);

        for (int i = 0; i < jumlahWilayah; i++) {
          digitalWrite(ledPins[i], led[i] ? HIGH : LOW);
        }
        kirimSegera = true;   // wilayah aktif langsung dikirim ke web

        gambarMenu();

      } else {
        // Klik SETTING
        halamanSetting = true;
        gambarSetting();
      }
    }
  }

  // -------------------------------------
  // UPDATE SIMULASI SETIAP 4 DETIK
  // HANYA WILAYAH YANG LED-NYA ON (wilayah aktif)
  // -------------------------------------
  if (sekarang - waktuUpdateData >= INTERVAL_SIMULASI_MS) {
    waktuUpdateData = sekarang;

    xSemaphoreTake(mutexData, portMAX_DELAY);
    for (int i = 0; i < jumlahWilayah; i++) {
      if (statusLED[i]) acakDataWilayah(i);
    }
    xSemaphoreGive(mutexData);

    if (halamanDetail && statusLED[pilihan]) {
      updateAngkaDetail();
    }
  }

  // -------------------------------------
  // STATUS WIFI / KIRIM DI POJOK KANAN ATAS
  // -------------------------------------
  if (!halamanSetting &&
      statusJaringanBerubah &&
      sekarang - waktuStatusUI >= 1000) {
    waktuStatusUI = sekarang;
    statusJaringanBerubah = false;
    gambarStatusJaringan();
  }

  // Simpan status tombol
  kiriSebelumnya = kiri;
  kananSebelumnya = kanan;
  okSebelumnya = ok;

  delay(5);
}

// =====================================
// AQI DARI PM2.5 (US EPA 2024) — sama dengan server
// =====================================
int hitungAQI(float pm25) {
  const float bp[6][4] = {
    {  0.0,   9.0,   0,  50},
    {  9.1,  35.4,  51, 100},
    { 35.5,  55.4, 101, 150},
    { 55.5, 125.4, 151, 200},
    {125.5, 225.4, 201, 300},
    {225.5, 325.4, 301, 500}
  };
  float c = floorf(max(pm25, 0.0f) * 10.0f) / 10.0f;
  for (int i = 0; i < 6; i++) {
    if (c <= bp[i][1]) {
      float cl = bp[i][0], ch = bp[i][1], il = bp[i][2], ih = bp[i][3];
      if (c < cl) c = cl;
      return (int) lroundf((ih - il) / (ch - cl) * (c - cl) + il);
    }
  }
  return 500;
}

// =====================================
// ACAK DATA WILAYAH (random walk kecil)
// =====================================
void acakDataWilayah(int i) {
  dataUdara[i].suhu += random(-2, 3) / 10.0;
  dataUdara[i].suhu = constrain(dataUdara[i].suhu, 20.0, 45.0);

  dataUdara[i].co += random(-3, 4) / 10.0;
  dataUdara[i].co = constrain(dataUdara[i].co, 0.0, 100.0);

  dataUdara[i].co2 += random(-10, 11);
  dataUdara[i].co2 = constrain(dataUdara[i].co2, 350, 2000);

  dataUdara[i].pm25 += random(-20, 21) / 10.0;
  dataUdara[i].pm25 = constrain(dataUdara[i].pm25, 0.0, 300.0);

  dataUdara[i].o2 += random(-1, 2) / 10.0;
  dataUdara[i].o2 = constrain(dataUdara[i].o2, 19.0, 21.0);
}

// =====================================
// TASK JARINGAN (CORE 0)
// Jaga koneksi WiFi + kirim semua wilayah ke backend
//
// Payload (nama field = jenis sensor di backend, "no" = id koridor):
//   {"aktif":1,"wilayah":[{"no":1,"temperature":31.0,"co":4580.0,"co2":480,
//                          "pm25":54.0,"o2":20.8,"air_quality":152,"led":true}]}
//   Semua OFF: {"aktif":0,"wilayah":[]}
// CO dikirim dalam ug/m3 (ppm x 1145), bukan ppm.
// =====================================
static bool sukses(int kode) { return kode == 200 || kode == 201; }

bool kirimTelemetri() {
  // Salin data dengan cepat, lalu lepas mutex sebelum HTTP (yang lambat)
  DataUdara d[jumlahWilayah];
  bool led[jumlahWilayah];
  xSemaphoreTake(mutexData, portMAX_DELAY);
  memcpy(d, dataUdara, sizeof(d));
  memcpy(led, statusLED, sizeof(led));
  xSemaphoreGive(mutexData);

  // Hanya SATU wilayah yang bisa aktif. "aktif" = nomor wilayah (1-5), 0 = semua OFF.
  // Wilayah yang OFF tidak dikirim, jadi di web hanya wilayah aktif yang tampil.
  int aktif = 0;
  for (int i = 0; i < jumlahWilayah; i++) {
    if (led[i]) { aktif = i + 1; break; }
  }

  char body[400];
  int n = snprintf(body, sizeof(body), "{\"aktif\":%d,\"wilayah\":[", aktif);
  if (aktif > 0) {
    int i = aktif - 1;
    n += snprintf(body + n, sizeof(body) - n,
                  "{\"no\":%d,\"temperature\":%.1f,\"co\":%.1f,\"co2\":%d,"
                  "\"pm25\":%.1f,\"o2\":%.1f,\"air_quality\":%d,\"led\":true}",
                  aktif, d[i].suhu, d[i].co * CO_PPM_KE_UGM3, d[i].co2,
                  d[i].pm25, d[i].o2, hitungAQI(d[i].pm25));
  }
  n += snprintf(body + n, sizeof(body) - n, "]}");
  if (n >= (int) sizeof(body)) {
    Serial.println("[KIRIM] Payload kebesaran, batal");
    return false;
  }

  WiFiClientSecure client;
  client.setInsecure();   // HTTPS terenkripsi; sertifikat server tidak diverifikasi

  HTTPClient http;
  http.setConnectTimeout(8000);
  http.setTimeout(8000);
  http.setUserAgent("JejakWangiESP32/" FW_VERSION);

  int kode = -1;
  if (http.begin(client, SERVER_URL)) {
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept", "application/json");
    http.addHeader("Authorization", "Bearer " DEVICE_TOKEN);
    kode = http.POST((uint8_t*) body, n);
    if (!sukses(kode)) {
      Serial.printf("[KIRIM] HTTP %d: %s\n", kode,
                    kode > 0 ? http.getString().c_str() : http.errorToString(kode).c_str());
    }
    http.end();
  }

  kodeHttpTerakhir = kode;
  if (sukses(kode)) {
    jumlahKirimOK = jumlahKirimOK + 1;
    Serial.printf("[KIRIM] OK #%lu (%d byte)\n", jumlahKirimOK, n);
  } else {
    jumlahKirimGagal = jumlahKirimGagal + 1;
  }
  statusJaringanBerubah = true;
  return sukses(kode);
}

void taskJaringan(void* param) {
  WiFi.mode(WIFI_STA);
  WiFi.setHostname(DEVICE_HOST);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.printf("[WIFI] Menghubungkan ke %s ...\n", WIFI_SSID);

  unsigned long cobaWifi = millis();
  unsigned long kirimTerakhir = 0;
  int gagalBeruntun = 0;

  for (;;) {
    bool nyambung = (WiFi.status() == WL_CONNECTED);

    if (nyambung != wifiTerhubung) {
      wifiTerhubung = nyambung;
      statusJaringanBerubah = true;
      if (nyambung) {
        Serial.printf("[WIFI] Terhubung, IP %s, RSSI %d dBm\n",
                      WiFi.localIP().toString().c_str(), WiFi.RSSI());
        kirimSegera = true;
      } else {
        Serial.println("[WIFI] Terputus");
      }
    }

    if (!nyambung) {
      // Coba sambung ulang tiap 15 detik
      if (millis() - cobaWifi > 15000) {
        cobaWifi = millis();
        WiFi.disconnect();
        WiFi.begin(WIFI_SSID, WIFI_PASS);
        Serial.println("[WIFI] Coba sambung ulang...");
      }
    } else {
      int r = WiFi.RSSI();
      if (abs(r - rssiTerakhir) >= 3) { rssiTerakhir = r; statusJaringanBerubah = true; }

      // Setelah gagal beruntun (termasuk 429 rate limit), beri jeda lebih lama (maks 60 dtk)
      unsigned long jeda = INTERVAL_KIRIM_MS * (gagalBeruntun > 5 ? 6 : 1);
      if (kirimSegera || millis() - kirimTerakhir >= jeda) {
        kirimSegera = false;
        kirimTerakhir = millis();
        gagalBeruntun = kirimTelemetri() ? 0 : gagalBeruntun + 1;
      }
    }
    vTaskDelay(pdMS_TO_TICKS(200));
  }
}

// =====================================
// STATUS JARINGAN DI POJOK KANAN ATAS
// =====================================
void gambarStatusJaringan() {
  tft.fillRect(196, 4, 122, 30, ILI9341_BLACK);
  tft.setTextSize(1);

  tft.setCursor(200, 8);
  if (wifiTerhubung) {
    tft.setTextColor(ILI9341_GREEN);
    tft.printf("WiFi %d dBm", rssiTerakhir);
  } else {
    tft.setTextColor(ILI9341_RED);
    tft.print("WiFi terputus");
  }

  tft.setCursor(200, 20);
  if (jumlahKirimOK == 0 && jumlahKirimGagal == 0) {
    tft.setTextColor(ILI9341_DARKGREY);
    tft.print("Server: menunggu");
  } else if (sukses(kodeHttpTerakhir)) {
    tft.setTextColor(ILI9341_GREEN);
    tft.printf("Server OK #%lu", jumlahKirimOK);
  } else {
    tft.setTextColor(ILI9341_ORANGE);
    tft.printf("Server gagal %d", kodeHttpTerakhir);
  }
}

// =====================================
// MENU UTAMA
// =====================================
void gambarMenu() {
  tft.fillScreen(ILI9341_BLACK);

  tft.setTextSize(2);
  tft.setTextColor(ILI9341_CYAN);
  tft.setCursor(15, 10);
  tft.println("JEJAK WANGI");

  tft.drawFastHLine(
    15, 38, 290, ILI9341_DARKGREY
  );

  // Lima wilayah + satu menu setting
  for (int i = 0; i < jumlahMenu; i++) {
    int y = 45 + i * 29;

    if (i == pilihan) {
      tft.fillRoundRect(
        10, y - 3, 300, 27, 5, ILI9341_BLUE
      );
    }

    tft.setTextSize(2);
    tft.setTextColor(
      i == pilihan
        ? ILI9341_WHITE
        : ILI9341_LIGHTGREY
    );

    tft.setCursor(20, y + 2);
    tft.print(i == pilihan ? "> " : "  ");

    if (i < jumlahWilayah) {
      // Menu wilayah
      tft.print("Wilayah ");
      tft.print(i + 1);

      tft.setCursor(230, y + 2);
      tft.setTextColor(
        statusLED[i]
          ? ILI9341_GREEN
          : ILI9341_RED
      );

      tft.print(statusLED[i] ? "ON" : "OFF");

    } else {
      // Menu tambahan
      tft.setTextColor(
        i == pilihan
          ? ILI9341_WHITE
          : ILI9341_YELLOW
      );

      tft.print("SETTING");
    }
  }

  tft.setTextSize(1);
  tft.setTextColor(ILI9341_YELLOW);
  tft.setCursor(12, 228);
  tft.println("Klik OK: ON/OFF | Tahan: DETAIL");

  gambarStatusJaringan();
}

// =====================================
// HALAMAN SETTING
// =====================================
void gambarSetting() {
  tft.fillScreen(ILI9341_BLACK);

  // Judul
  tft.setTextSize(2);
  tft.setTextColor(ILI9341_CYAN);
  tft.setCursor(15, 12);
  tft.println("SETTING");

  // Info jaringan singkat di kanan judul
  tft.setTextSize(1);
  tft.setCursor(130, 10);
  tft.setTextColor(wifiTerhubung ? ILI9341_GREEN : ILI9341_RED);
  if (wifiTerhubung) {
    tft.print("IP ");
    tft.print(WiFi.localIP());
  } else {
    tft.print("WiFi terputus");
  }
  tft.setCursor(130, 22);
  tft.setTextColor(ILI9341_LIGHTGREY);
  tft.print("fw " FW_VERSION "  " DEVICE_HOST);

  tft.drawFastHLine(
    15, 40, 290, ILI9341_DARKGREY
  );

  // Logo Instagram sederhana
  int logoX = 116;
  int logoY = 65;

  // Bingkai logo
  tft.drawRoundRect(
    logoX, logoY, 88, 88, 20, ILI9341_MAGENTA
  );

  tft.drawRoundRect(
    logoX + 7, logoY + 7, 74, 74, 16,
    ILI9341_MAGENTA
  );

  // Lensa kamera
  tft.drawCircle(
    logoX + 44, logoY + 44, 19,
    ILI9341_MAGENTA
  );

  tft.drawCircle(
    logoX + 44, logoY + 44, 12,
    ILI9341_MAGENTA
  );

  // Titik kamera
  tft.fillCircle(
    logoX + 66, logoY + 22, 5,
    ILI9341_MAGENTA
  );

  // Username Instagram
  tft.setTextSize(2);
  tft.setTextColor(ILI9341_WHITE);

  tft.setCursor(10, 166);
  tft.println("Instagram");

  tft.setTextSize(2);
  tft.setTextColor(ILI9341_CYAN);

  tft.setCursor(10, 190);
  tft.println(usernameInstagram);

  // Nama pembuat
  tft.setTextSize(1);
  tft.setTextColor(ILI9341_LIGHTGREY);

  tft.setCursor(10, 216);
  tft.println("Created by:");

  tft.setTextSize(2);
  tft.setTextColor(ILI9341_YELLOW);

  tft.setCursor(100, 211);
  tft.println(namaPembuat);

  // Petunjuk
  tft.setTextSize(1);
  tft.setTextColor(ILI9341_GREEN);

  tft.setCursor(10, 233);
  tft.println("Tekan OK untuk kembali");
}

// =====================================
// HALAMAN DETAIL
// =====================================
const int yBaris[] = {68, 90, 112, 134, 156, 178};

void gambarDetail() {
  tft.fillScreen(ILI9341_BLACK);

  tft.setTextSize(2);
  tft.setTextColor(ILI9341_CYAN);
  tft.setCursor(15, 10);
  tft.println("DETAIL");

  tft.drawFastHLine(
    15, 38, 290, ILI9341_DARKGREY
  );

  tft.setTextColor(ILI9341_YELLOW);
  tft.setCursor(15, 44);
  tft.print("Wilayah ");
  tft.println(pilihan + 1);

  tft.setTextColor(ILI9341_WHITE);
  const char* label[] = {"Suhu  :", "CO    :", "CO2   :", "PM2.5 :", "O2    :", "AQI   :"};
  for (int i = 0; i < 6; i++) {
    tft.setCursor(15, yBaris[i]);
    tft.print(label[i]);
  }

  tft.setCursor(15, 204);
  tft.setTextColor(
    statusLED[pilihan]
      ? ILI9341_GREEN
      : ILI9341_RED
  );

  tft.print("LED: ");
  tft.println(
    statusLED[pilihan] ? "ON" : "OFF"
  );

  tft.setTextSize(1);
  tft.setTextColor(ILI9341_YELLOW);
  tft.setCursor(15, 232);
  tft.println("Klik OK untuk kembali");

  updateAngkaDetail();
  gambarStatusJaringan();
}

// =====================================
// UPDATE ANGKA TANPA HAPUS SELURUH LCD
// =====================================
void updateAngkaDetail() {
  xSemaphoreTake(mutexData, portMAX_DELAY);
  DataUdara d = dataUdara[pilihan];
  xSemaphoreGive(mutexData);

  int aqi = hitungAQI(d.pm25);

  tft.setTextSize(2);

  // Suhu
  tft.fillRect(110, yBaris[0], 205, 18, ILI9341_BLACK);
  tft.setTextColor(d.suhu >= 35 ? ILI9341_ORANGE : ILI9341_WHITE);
  tft.setCursor(110, yBaris[0]);
  tft.print(d.suhu, 1);
  tft.print(" C");

  // CO
  tft.fillRect(110, yBaris[1], 205, 18, ILI9341_BLACK);
  tft.setTextColor(d.co >= 9 ? ILI9341_ORANGE : ILI9341_WHITE);
  tft.setCursor(110, yBaris[1]);
  tft.print(d.co, 1);
  tft.print(" ppm");

  // CO2
  tft.fillRect(110, yBaris[2], 205, 18, ILI9341_BLACK);
  tft.setTextColor(d.co2 >= 1000 ? ILI9341_ORANGE : ILI9341_WHITE);
  tft.setCursor(110, yBaris[2]);
  tft.print(d.co2);
  tft.print(" ppm");

  // PM2.5
  tft.fillRect(110, yBaris[3], 205, 18, ILI9341_BLACK);
  tft.setTextColor(ILI9341_WHITE);
  tft.setCursor(110, yBaris[3]);
  tft.print(d.pm25, 1);
  tft.print(" ug/m3");

  // O2
  tft.fillRect(110, yBaris[4], 205, 18, ILI9341_BLACK);
  tft.setTextColor(d.o2 < 19.5 ? ILI9341_ORANGE : ILI9341_WHITE);
  tft.setCursor(110, yBaris[4]);
  tft.print(d.o2, 1);
  tft.print("%");

  // AQI + warna kategori
  uint16_t warna = aqi <= 50 ? ILI9341_GREEN
                 : aqi <= 100 ? ILI9341_YELLOW
                 : aqi <= 150 ? ILI9341_ORANGE
                 : ILI9341_RED;
  tft.fillRect(110, yBaris[5], 205, 18, ILI9341_BLACK);
  tft.setTextColor(warna);
  tft.setCursor(110, yBaris[5]);
  tft.print(aqi);
}
