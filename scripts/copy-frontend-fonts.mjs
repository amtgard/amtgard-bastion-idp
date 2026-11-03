import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const assetsDir = join(root, 'public', 'assets');
const cssPath = join(assetsDir, 'idp.css');

const fontSources = [
  ['@fontsource/roboto-condensed', 'roboto-condensed-latin-400-normal.woff2'],
  ['@fontsource/roboto-condensed', 'roboto-condensed-latin-500-normal.woff2'],
  ['@fontsource/roboto-condensed', 'roboto-condensed-latin-600-normal.woff2'],
  ['@fontsource/roboto-condensed', 'roboto-condensed-latin-700-normal.woff2'],
  ['@fontsource/roboto-condensed', 'roboto-condensed-latin-400-italic.woff2'],
  ['@fontsource/roboto-condensed', 'roboto-condensed-latin-500-italic.woff2'],
  ['@fontsource/roboto-condensed', 'roboto-condensed-latin-700-italic.woff2'],
  ['@fontsource/instrument-serif', 'instrument-serif-latin-400-normal.woff2'],
  ['@fontsource/instrument-serif', 'instrument-serif-latin-400-italic.woff2'],
];

const faWoff2 = ['fa-brands-400.woff2', 'fa-solid-900.woff2'];

let css = readFileSync(cssPath, 'utf8');
css = css.replaceAll('url(files/', 'url(/assets/fonts/');
css = css.replaceAll('url(../webfonts/', 'url(/assets/webfonts/');
// Strip the woff/ttf fallback source AND its format() hint. Stripping only
// the url() left an orphan format("woff") / format("truetype") behind the woff2
// url, giving every @font-face a src of
//   url(data:font/woff2;base64,...) format("woff2") format("woff")
// Two format() hints for one url is invalid CSS, so browsers drop the whole
// @font-face rule - which is why neither the text fonts nor the Font Awesome
// icons loaded at all, even though the woff2 data itself was valid.
css = css.replaceAll(/,\s*url\([^)]+\.(?:woff|ttf)\)\s*format\([^)]+\)/g, '');

const resolveAssetPath = (dir, file) => {
  if (dir === 'fonts') {
    for (const [pkg, name] of fontSources) {
      if (name === file) {
        return join(root, 'node_modules', pkg, 'files', name);
      }
    }
  }
  if (dir === 'webfonts' && faWoff2.includes(file)) {
    return join(root, 'node_modules', '@fortawesome', 'fontawesome-free', 'webfonts', file);
  }
  return null;
};

css = css.replaceAll(/url\(\/assets\/(fonts|webfonts)\/([^)]+\.woff2)\)/g, (match, dir, file) => {
  const src = resolveAssetPath(dir, file);
  if (src === null || !existsSync(src)) {
    throw new Error(`Cannot inline font: /assets/${dir}/${file}`);
  }
  const b64 = readFileSync(src).toString('base64');
  return `url(data:font/woff2;base64,${b64})`;
});

writeFileSync(cssPath, css);

for (const sub of ['fonts', 'webfonts']) {
  const path = join(assetsDir, sub);
  if (existsSync(path)) {
    rmSync(path, { recursive: true, force: true });
  }
}
