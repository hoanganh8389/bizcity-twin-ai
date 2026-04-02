import { Moment } from 'moment-timezone';
import { CSSProperties } from 'react';
import { CSSObject } from '@emotion/react';

export interface TooltipProps {
  showTooltip: boolean;
  name: string;
  startTime: Moment;
  endTime: Moment;
  description?: string;
  location?: string;
  calendarName?: string;
  closeTooltip: () => void;
  tooltipStyles?: CSSProperties | CSSObject;
}

export interface TooltipState {
  timeDisplay: string;
  eventURL: string;
}

