import axios, { AxiosError } from 'axios';

const fs = require('fs');
const path = require('path');

// Define interfaces for type safety
interface FontData {
  family: string;
  styles: {
    name: string;
    style: string;
    url: string;
  }[];
}

interface MockData {
  data: FontData[];
}
// Load mock data
const loadMockData = (): MockData => {
  try {
    const data = fs.readFileSync(path.join(__dirname, 'json/fonts.json'), 'utf8');
    return JSON.parse(data);
  } catch (error) {
    throw new Error(`Failed to load mock data: ${(error as Error).message}`);
  }
};

// Import frame data to Strapi
export async function importFontData(
  apiUrl: string,
  token: string,
): Promise<{ success: boolean; imported: number; errors: string[] }> {
  if (!token) {
    throw new Error('API token is required');
  }

  const mockData = loadMockData();
  const errors: string[] = [];
  let imported = 0;

  for (const item of mockData.data) {
    try {
      await axios.post(
        apiUrl,
        {
          data: item,
        },
        {
          headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
          },
        }
      );
      console.log(`Successfully imported: ${item.family}`);
      imported++;
    } catch (error) {
      const errorMessage = `Error importing ${item.family}: ${
        JSON.stringify((error as AxiosError).response?.data) || (error as Error).message
      }`;

      errors.push(errorMessage);
    }
  }

  return { success: errors.length === 0, imported, errors };
}