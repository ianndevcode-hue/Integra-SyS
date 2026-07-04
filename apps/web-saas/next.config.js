/** @type {import('next').NextConfig} */
const nextConfig = {
  transpilePackages: ['@integra/ui', '@integra/types', '@integra/shared'],
  output: 'standalone',
};

module.exports = nextConfig;
