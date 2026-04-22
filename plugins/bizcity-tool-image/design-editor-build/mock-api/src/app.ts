import { importFrameData } from "./data-ingestion/frame";
import { importImageData } from "./data-ingestion/image";
import { importTextData } from "./data-ingestion/text";
import { importShapeData } from "./data-ingestion/shape";
import { importMasterTemplateData } from "./data-ingestion/template";
import { importFontData } from "./data-ingestion/font";
const express = require('express');
const cors = require('cors');
const app = express();
// const exportHandler = require('./export-handler');
app.use(cors());

const fs = require('fs');
const path = require('path');
app.use(express.static(__dirname + '/public')); //Serves resources from public folder

function paginateArrayWithFilter(array, size = 30, index = 0, keyword = '') {
  const startIndex = index * size;
  const endIndex = startIndex + size;
  let filteredArray = array;
  if (keyword && keyword !== '') {
    const lowerCaseKeyword = keyword.toLowerCase();
    filteredArray = array.filter((item) =>
      JSON.stringify(item).toLowerCase().includes(lowerCaseKeyword)
    );
  }

  return filteredArray.slice(startIndex, endIndex);
}

function handleFontStyleName(fontName, style) {
  if (style === 'regular') return fontName + ' Regular';

  const fontStrong = parseInt(style);
  if (style.includes('italic')) {
    return fontName + (fontStrong ? ` Italic Bold ${fontStrong}` : ' Italic');
  }

  if (!fontStrong) return fontName + ' Regular';
  return fontName + ` Bold ${fontStrong}`;
}

function searchKeywords(query, data) {
  if (!query) return [];
  const lowerCaseQuery = query.toLowerCase();
  const uniqueKeywords = new Set();

  data.forEach((item) => {
    const lowerCaseDesc = item.desc.toLowerCase();
    const keywords = lowerCaseDesc.split(' ');

    keywords.forEach((keyword) => {
      if (keyword.includes(lowerCaseQuery)) {
        uniqueKeywords.add(keyword);
      }
    });
  });

  return Array.from(uniqueKeywords);
}

/**
 * Get draft fonts
 */
app.get('/api/draft-fonts', async (req, res) => {
  const jsonPath = path.join(__dirname, 'json', 'draft-fonts.json');
  try {
    const jsonString = await fs.promises.readFile(jsonPath, 'utf8');
    const filtered = JSON.parse(jsonString).items.map((font) => ({
      family: font.family,
      styles: Object.keys(font.files).map((style) => ({
        name: handleFontStyleName(font.family, style),
        style,
        url: font.files[style],
      })),
    }));
    res.send({ data: filtered });
  } catch (err) {
    console.error('Error reading draft fonts:', err);
    res.status(500).send({ error: 'Failed to load draft fonts' });
  }
});

/**
 * Get fonts
 */
app.get('/api/fonts', async (req, res) => {
  const jsonPath = path.join(__dirname, 'json', 'fonts.json');
  try {
    const jsonString = await fs.promises.readFile(jsonPath, 'utf8');
    const { ps, pi, kw } = req.query;
    const filtered = paginateArrayWithFilter(JSON.parse(jsonString).data, +ps, +pi, kw);
    res.send({ data: filtered });
  } catch (err) {
    console.error('Error reading fonts:', err);
    res.status(500).send({ error: 'Failed to load fonts' });
  }
});


/**
 * Search templates
 */
app.get('/api/search-templates', async (req, res) => {
  const jsonPath = path.join(__dirname, 'json', 'templates.json');
  try {
    const jsonString = await fs.promises.readFile(jsonPath, 'utf8');
    const { ps, pi, kw } = req.query;
    const filtered = paginateArrayWithFilter(JSON.parse(jsonString).data, +ps, +pi, kw).map((item) => {
      return {
        ...item,
        data: JSON.parse(item.data),
      };
    });
    res.send({ data: filtered });
  } catch (err) {
    console.error('Error reading templates:', err);
    res.status(500).send({ error: 'Failed to load templates' });
  }
});

/**
 * Search template keywords
 */
app.get('/api/template-suggestion', async (req, res) => {
  const jsonPath = path.join(__dirname, 'json', 'templates.json');
  try {
    const jsonString = await fs.promises.readFile(jsonPath, 'utf8');
    const rs = searchKeywords(req.query.kw, JSON.parse(jsonString).data);
    res.send(rs.map((kw, idx) => ({ id: idx + 1, name: kw })));
  } catch (err) {
    console.error('Error reading templates:', err);
    res.status(500).send({ error: 'Failed to load templates' });
  }
});

/**
 * Search text templates
 */
app.get('/api/search-texts', async (req, res) => {
  const jsonPath = path.join(__dirname, 'json', 'texts.json');
  try {
    const jsonString = await fs.promises.readFile(jsonPath, 'utf8');
    const { ps, pi, kw } = req.query;
    const filtered = paginateArrayWithFilter(JSON.parse(jsonString).data, +ps, +pi, kw).map((item) => {
      return {
        ...item,
        data: JSON.parse(item.data),
      };
    });
    res.send({ data: filtered });
  } catch (err) {
    console.error('Error reading texts:', err);
    res.status(500).send({ error: 'Failed to load texts' });
  }
});

/**
 * Search text keywords
 */
app.get('/api/text-suggestion', async (req, res) => {
  const jsonPath = path.join(__dirname, 'json', 'texts.json');
  try {
    const jsonString = await fs.promises.readFile(jsonPath, 'utf8');
    const rs = searchKeywords(req.query.kw, JSON.parse(jsonString).data);
    res.send(rs.map((kw, idx) => ({ id: idx + 1, name: kw })));
  } catch (err) {
    console.error('Error reading texts:', err);
    res.status(500).send({ error: 'Failed to load texts' });
  }
});

/**
 * Search frames
 */
app.get('/api/search-frames', async (req, res) => {
  const jsonPath = path.join(__dirname, 'json', 'frames.json');
  try {
    const jsonString = await fs.promises.readFile(jsonPath, 'utf8');
    const { ps, pi, kw } = req.query;
    const filtered = paginateArrayWithFilter(JSON.parse(jsonString).data, +ps, +pi, kw);
    res.send({ data: filtered });
  } catch (err) {
    console.error('Error reading frames:', err);
    res.status(500).send({ error: 'Failed to load frames' });
  }
});

/**
 * Search frame keywords
 */
app.get('/api/frame-suggestion', async (req, res) => {
  const jsonPath = path.join(__dirname, 'json', 'frames.json');
  try {
    const jsonString = await fs.promises.readFile(jsonPath, 'utf8');
    const rs = searchKeywords(req.query.kw, JSON.parse(jsonString).data);
    res.send(rs.map((kw, idx) => ({ id: idx + 1, name: kw })));
  } catch (err) {
    console.error('Error reading frames:', err);
    res.status(500).send({ error: 'Failed to load frames' });
  }
});

/**
 * Search shapes
 */
app.get('/api/search-shapes', async (req, res) => {
  const jsonPath = path.join(__dirname, 'json', 'shapes.json');
  try {
    const jsonString = await fs.promises.readFile(jsonPath, 'utf8');
    const { ps, pi, kw } = req.query;
    const filtered = paginateArrayWithFilter(JSON.parse(jsonString).data, +ps, +pi, kw);
    res.send({ data: filtered });
  } catch (err) {
    console.error('Error reading shapes:', err);
    res.status(500).send({ error: 'Failed to load shapes' });
  }
});

/**
 * Search shape keywords
 */
app.get('/api/shape-suggestion', async (req, res) => {
  const jsonPath = path.join(__dirname, 'json', 'shapes.json');
  try {
    const jsonString = await fs.promises.readFile(jsonPath, 'utf8');
    const rs = searchKeywords(req.query.kw, JSON.parse(jsonString).data);
    res.send(rs.map((kw, idx) => ({ id: idx + 1, name: kw })));
  } catch (err) {
    console.error('Error reading shapes:', err);
    res.status(500).send({ error: 'Failed to load shapes' });
  }
});

/**
 * Search images
 */
app.get('/api/search-images', async (req, res) => {
  const jsonPath = path.join(__dirname, 'json', 'images.json');
  try {
    const jsonString = await fs.promises.readFile(jsonPath, 'utf8');
    const { ps, pi, kw } = req.query;
    const filtered = paginateArrayWithFilter(JSON.parse(jsonString).data, +ps, +pi, kw);
    res.send({ data: filtered });
  } catch (err) {
    console.error('Error reading images:', err);
    res.status(500).send({ error: 'Failed to load images' });
  }
});

/**
 * Search image keywords
 */
app.get('/api/image-suggestion', async (req, res) => {
  const jsonPath = path.join(__dirname, 'json', 'images.json');
  try {
    const jsonString = await fs.promises.readFile(jsonPath, 'utf8');
    const rs = searchKeywords(req.query.kw, JSON.parse(jsonString).data);
    res.send(rs.map((kw, idx) => ({ id: idx + 1, name: kw })));
  } catch (err) {
    console.error('Error reading images:', err);
    res.status(500).send({ error: 'Failed to load images' });
  }
});

/**
 * Data ingestion
 * Ensure your Strapi application is running (e.g., at http://localhost:1337).
 * Verify that the frame content-type is correctly set up in Strapi as per your schema.
 * Obtain an API token:
 * - Go to Strapi Admin Panel → Settings → API Tokens.
 * - Create a new token with "Full Access" or "Create" permissions for the frame content-type.
 * - Copy the token.
 */
export const STRAPI_URL = 'http://localhost:1337/api';
const API_TOKEN = '5ed0aac13c2bb721b93387c18e12da590f9474dcfb2d6b20df814e150ced516ed891db91682971e573b978082f41e0d86492c71a322312ade016670c8540efa5a6b2931184abc9274e309f6607e7f212911253ee7b3ce90905a30166334f1c01e8b9613bf2ee5afc31f3a23312cab2c4d934a411331896a3fa91d9f2279d2954'; // Replace with your API token
const FOLDER_IDS = {
  frame: 7,
  image: 8,
  text: 5,
  shape: 6,
  masterTemplate: 9,
};

app.get('/api/import-font-data', async (req, res) => {
  const api = `${STRAPI_URL}/fonts`;
  const rs = await importFontData(api, API_TOKEN);
  res.send(rs);
});

app.get('/api/import-frame-data', async (req, res) => {
  const api = `${STRAPI_URL}/frames`;
  const rs = await importFrameData(api, API_TOKEN, FOLDER_IDS.frame);
  res.send(rs);
});

app.get('/api/import-image-data', async (req, res) => {
  const api = `${STRAPI_URL}/images`;
  const rs = await importImageData(api, API_TOKEN, FOLDER_IDS.image);
  res.send(rs);
});

app.get('/api/import-text-data', async (req, res) => {
  const api = `${STRAPI_URL}/texts`;
  const rs = await importTextData(api, API_TOKEN, FOLDER_IDS.text);
  res.send(rs);
});

app.get('/api/import-shape-data', async (req, res) => {
  const api = `${STRAPI_URL}/shapes`;
  const rs = await importShapeData(api, API_TOKEN, FOLDER_IDS.shape);
  res.send(rs);
});

app.get('/api/import-master-template-data', async (req, res) => {
  const api = `${STRAPI_URL}/master-templates`;
  const rs = await importMasterTemplateData(api, API_TOKEN, FOLDER_IDS.masterTemplate);
  res.send(rs);
});

export default app;

