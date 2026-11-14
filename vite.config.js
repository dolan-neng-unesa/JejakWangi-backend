<<<<<<< HEAD
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
=======
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
>>>>>>> 11277a5008152cfb3fea2a9f9fdf4ba8d2265cde
});
