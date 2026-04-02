import { CSSProperties } from 'react';
import { CSSObject } from '@emotion/react';
import { Moment } from 'moment-timezone';

export interface MultiEventProps {
  name: string;
  startTime: string | number | Date | Moment;
  endTime: string | number | Date | Moment;
  length?: number;
  description?: string;
  location?: string;
  calendarName?: string;
  tooltipStyles?: CSSProperties | CSSObject;
  multiEventStyles?: CSSProperties | CSSObject;
  color?: string;
  arrowLeft?: boolean;
  arrowRight?: boolean;
}

export interface MultiEventState {
  startTime: Moment;
  endTime: Moment;
  color: string;
  darkColor: string;
  showTooltip: boolean;
  allDay: boolean;
}
