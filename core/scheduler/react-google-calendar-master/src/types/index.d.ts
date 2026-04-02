import { Moment } from 'moment-timezone';

export interface CalendarProps {
  apiKey: string;
  calendars: {
    calendarId: string,
    color?: string,
  }[];
  language?: string;
  styles?: object;
  showArrow?: boolean;
  showFooter?: boolean;
}

export interface CalendarState {
  monthNames: string[];
  days: string[];
  today: Moment;
  current: Moment;
  calendars: {
    calendarId: string,
    color?: string,
  }[];
  events: any[];
  singleEvents: any[];
  userTimezone: string;
  showArrow: boolean;
  showFooter: boolean;
  processedCalendars: string[];
}
