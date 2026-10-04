import type { NextConfig } from "next";

const LARAVEL_URL = (process.env.LARAVEL_URL || "http://127.0.0.1:8012").replace(/\/+$/, "");

const nextConfig: NextConfig = {
  async rewrites() {
    return [
      { source: "/api/:path*", destination: `${LARAVEL_URL}/api/:path*` },
      { source: "/sanctum/:path*", destination: `${LARAVEL_URL}/sanctum/:path*` },
    ];
  },
};

export default nextConfig;
