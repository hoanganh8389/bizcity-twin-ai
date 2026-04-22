import { uploadImageToStrapi } from 'apps/mock-api/helpers/upload-image';
import axios, { AxiosError } from 'axios';

const fs = require('fs');
const path = require('path');

// Define interfaces for type safety
interface ShapeData {
  desc: string;
  img: string;
  clipPath: string;
  width: string;
  height: string;
  background: string;
}

interface MockData {
  data: ShapeData[];
}
// Load mock data
const loadMockData = (): MockData => {
  try {
    const data = fs.readFileSync(path.join(__dirname, 'json/shapes.json'), 'utf8');
    return JSON.parse(data);
  } catch (error) {
    throw new Error(`Failed to load mock data: ${(error as Error).message}`);
  }
};

// Import shape data to Strapi
export async function importShapeData(
  apiUrl: string,
  token: string,
  folderId: number
): Promise<{ success: boolean; imported: number; errors: string[] }> {
  if (!token) {
    throw new Error('API token is required');
  }

  const mockData = loadMockData();
  const errors: string[] = [];
  let imported = 0;

  for (const item of mockData.data) {
    try {
      const imageId = await uploadImageToStrapi(item.img, token, folderId);
      await axios.post(
        apiUrl,
        {
          data: {
            img: imageId,
            desc: item.desc,
            clipPath: item.clipPath,
            width: item.width,
            height: item.height,
            background: item.background,
          },
        },
        {
          headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
          },
        }
      );
      console.log(`Successfully imported: ${item.desc}`);
      imported++;
    } catch (error) {
      const errorMessage = `Error importing ${item.desc}: ${
        JSON.stringify((error as AxiosError).response?.data) || (error as Error).message
      }`;

      errors.push(errorMessage);
    }
  }

  return { success: errors.length === 0, imported, errors };
}