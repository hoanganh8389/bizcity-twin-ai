import axios, { AxiosError } from 'axios';
import { uploadImageToStrapi } from '../../helpers';

const fs = require('fs');
const path = require('path');

// Define interfaces for type safety
interface TextData {
  desc: string;
  data: string;
  img: string;
}

interface MockData {
  data: TextData[];
}
// Load mock data
const loadMockData = (): MockData => {
  try {
    const data = fs.readFileSync(path.join(__dirname, 'json/texts.json'), 'utf8');
    return JSON.parse(data);
  } catch (error) {
    throw new Error(`Failed to load mock data: ${(error as Error).message}`);
  }
};

// Import image data to Strapi
export async function importTextData(
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
      // Upload image and get media ID
      const imageId = await uploadImageToStrapi(item.img, token, folderId);
      // Create record with media ID
      await axios.post(
        apiUrl,
        {
          data: {
            img: imageId, // Use media ID instead of URL
            desc: item.desc,
            data: item.data,
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