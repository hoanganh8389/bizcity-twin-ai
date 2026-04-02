/** @type {import('vite').UserConfig} */
import react from '@vitejs/plugin-react';
import dts from 'vite-plugin-dts';
import cssInjectedByJsPlugin from 'vite-plugin-css-injected-by-js'
import { defineConfig } from 'vite';
import path from 'path';

export default defineConfig(({ command, mode }) => {
  return {
    plugins: [
      react({ 
        jsxImportSource: '@emotion/react',
        babel: {
          plugins: ["@emotion/babel-plugin"],
        },
      }),
      dts({
        insertTypesEntry: true,
        copyDtsFiles: true
      }),
      cssInjectedByJsPlugin()
    ],
    build: {
      // sourcemap: true,
      // minify: false,
      lib: {
        entry: path.resolve(__dirname, 'src/index.tsx'),
        name: 'react-google-calendar',
        formats: ['es', 'umd'],
        fileName: (format) => `react-google-calendar.${format}.js`,
      },
      rollupOptions: {
        external: ['react', 'react-dom', '@emotion/react'],
        output: {
          globals: {
            react: 'React',
            'react-dom': 'ReactDOM',
            '@emotion/react': '@emotion/react',
          },
        }
      },
    }
  };
});
