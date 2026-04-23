import type { NextConfig } from "next";

const nextConfig: NextConfig = {};

export default nextConfig;

// Expose Cloudflare bindings (KV, R2, D1…) during `next dev`
import { initOpenNextCloudflareForDev } from "@opennextjs/cloudflare";
initOpenNextCloudflareForDev();
