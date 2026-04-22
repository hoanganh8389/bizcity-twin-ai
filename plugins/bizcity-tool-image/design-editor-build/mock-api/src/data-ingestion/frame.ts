import { uploadImageToStrapi } from 'apps/mock-api/helpers/upload-image';
import axios, { AxiosError } from 'axios';

const fs = require('fs');
const path = require('path');

// Define interfaces for type safety
interface FrameData {
  img: string;
  clipPath: string;
  width: string;
  height: string;
  desc: string;
}

interface MockData {
  data: FrameData[];
}
// Load mock data
const loadMockData = (): MockData => {
  try {
    const data = fs.readFileSync(path.join(__dirname, 'json/frames.json'), 'utf8');
    return JSON.parse(data);
  } catch (error) {
    throw new Error(`Failed to load mock data: ${(error as Error).message}`);
  }
};

// Import frame data to Strapi
export async function importFrameData(
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
            clipPath: item.clipPath,
            width: parseFloat(item.width),
            height: parseFloat(item.height),
            desc: item.desc,
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