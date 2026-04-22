import axios from 'axios';
import path from 'path';
import FormData from 'form-data';
import { STRAPI_URL } from '../src/app';

// Function to download image and return buffer
async function downloadImage(url: string): Promise<Buffer> {
  const response = await fetch(url);
  if (!response.ok) {
    throw new Error(`Failed to download image from ${url}`);
  }
  return Buffer.from(await response.arrayBuffer());
}

export async function uploadImageToStrapi(
  imageUrl: string,
  token: string,
  folderId?: number,
  contentType?: string,
  refId?: number,
  field?: string
): Promise<number> {
  try {
    const imageBuffer = await downloadImage(imageUrl);
    const formData = new FormData();
    const ext = path.extname(imageUrl) || '.png';
    const filename = `text_image_${new Date().getTime()}${ext}`;

    formData.append('files', imageBuffer, {
      filename,
      contentType: 'image/png',
    });

    formData.append('folder', folderId);
    if (contentType) {
      formData.append('ref', contentType);
    }
    if (refId) {
      formData.append('refId', refId.toString());
    }
    if (field) {
      formData.append('field', field);
    }
    formData.append(
      'fileInfo',
      JSON.stringify({
        caption: '',
        alternativeText: '',
        name: filename,
        folder: folderId,
      })
    );

    if (folderId) {
      console.log(`Uploading to folder ID: ${folderId}`);
    } else {
      console.log('No folder ID provided, uploading to default folder');
    }

    const response = await axios.post(`${STRAPI_URL}/upload`, formData, {
      headers: {
        Authorization: `Bearer ${token}`,
        ...formData.getHeaders(),
      },
    });

    // Return the ID of the uploaded media
    return response.data[0].id;
  } catch (error) {
    throw new Error(
      `Failed to upload image: ${
        (error as Error).message
      }. Seems like the folder does not exist.`
    );
  }
}
