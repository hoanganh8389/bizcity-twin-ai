import { CSSProperties } from 'react';
import { CSSObject } from '@emotion/react';
import { Moment } from 'moment-timezone';

export interface EventProps {
  name: string;
  startTime: string | number | Date | Moment;
  endTime: string | number | Date | Moment;
  description?: string;
  location?: string;
  eventStyles?: CSSProperties | CSSObject;
  eventCircleStyles?: CSSProperties | CSSObject;
  eventTextStyles?: CSSProperties | CSSObject;
  tooltipStyles?: CSSProperties | CSSObject;
  calendarName?: string;
  color?: string;
}

export interface EventState {
  startTime: Moment;
  endTime: Moment;
  showTooltip: boolean;
}
