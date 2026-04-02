import type { Meta, StoryObj } from '@storybook/react';

import Calendar from '../index';

const meta = {
  title: 'Calendar/Calendar',
  component: Calendar,
  parameters: {
    layout: 'centered',
  },
  tags: ['autodocs'],
  argTypes: {
    apiKey: { control: 'text' },
    calendars: { control: 'object' },
    language: { control: 'text' },
    styles: { control: 'object' },
  }
} as Meta<typeof Calendar>;

export default meta;
type Story = StoryObj<typeof meta>;
export const DefaultCalendar: Story = {
  args: {
    calendars: [{ calendarId: "09opmkrjova8h5k5k46fedmo88@group.calendar.google.com" }]
  }
};