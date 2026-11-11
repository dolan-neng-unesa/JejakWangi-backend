import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

// jejak-wangi-app memakai port 5173; admin dibuat 5174 supaya bisa jalan berdampingan.
// CORS backend (config/cors.php) sudah mengizinkan localhost:<port apa pun>.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: { port: 5174, host: true },
  preview: { port: 5174, host: true },
  test: { environment: "jsdom", include: ["src/**/*.test.{js,jsx}"] },
});
