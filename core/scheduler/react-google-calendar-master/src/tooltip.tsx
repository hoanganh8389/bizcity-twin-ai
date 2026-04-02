/** @jsxImportSource @emotion/react */

import React from "react";

import moment from "moment-timezone";

import "./index.css";

import { css, jsx } from '@emotion/react';

import { Popper } from 'react-popper';

import { isAllDay, getCalendarURL } from "./utils/helper";

import Place from "./svg/place";
import Subject from "./svg/subject";
import CalendarToday from "./svg/calendarToday";

import { TooltipProps, TooltipState } from './types/tooltip';

export default class Tooltip extends React.Component<TooltipProps, TooltipState> {
  constructor(props: TooltipProps) {
    super(props);
    let allDay = isAllDay(this.props.startTime, this.props.endTime);

    this.state = {
      timeDisplay: Tooltip.getTimeDisplay(this.props.startTime, this.props.endTime, allDay),
      eventURL: getCalendarURL(this.props.startTime, this.props.endTime, this.props.name, this.props.description, this.props.location, allDay),
    }
    
  }

  static getTimeDisplay(startTime: moment.Moment, endTime: moment.Moment, allDay: boolean): string {
    if (allDay) {
      let endDate = moment(endTime).subtract(1, "day");

      if (endDate.isSame(startTime, "day")) {
        return startTime.format("dddd, MMMM Do");
      } else {
        return startTime.format("MMM Do, YYYY") + " - " + endDate.format("MMM Do, YYYY");
      }
    } else {
      if (endTime.isSame(startTime, "day")) {
        return startTime.format("dddd, MMMM Do") + "\n" 
          + startTime.format("h:mma") + " - " + endTime.format("h:mma");
      } else {
        return startTime.format("MMM Do, YYYY, h:mma") + " -\n" + endTime.format("MMM Do, YYYY, h:mma");
      }
    }
  }

  render() {
    let description: JSX.Element;
    if (this.props.description) {
      description = <div className="details description">
        <div css={{ paddingRight: "10px" }}><Subject fill="currentColor" /></div>
        <div css={{ overflowWrap: "break-word", maxWidth: "calc(100% - 28px)" }}
          // @ts-ignore
          onMouseDown={e => {if (e.target.nodeName == 'A') {e.preventDefault()}}} 
          dangerouslySetInnerHTML={{__html: this.props.description}} />
      </div>;
    } else {
      description = <div></div>;
    }

    let location: JSX.Element;
    if (this.props.location) {
      location = <div className="details location">
        <div css={{ paddingRight: "10px", display: "flex", alignItems: "center"}}><Place fill="currentColor" /></div>
        <div css={{ overflowWrap: "break-word", maxWidth: "calc(100% - 28px)" }}>{this.props.location}</div>
      </div>;
    } else {
      location = <div></div>;
    }

    let calendarName: JSX.Element;
    if (this.props.calendarName) {
      calendarName = <div className="details calendarName">
        <div css={{ paddingRight: "10px", display: "flex", alignItems: "center" }}><CalendarToday fill="currentColor" /></div>
        <div>{this.props.calendarName}</div>
      </div>;
    } else {
      calendarName = <div></div>;
    }

    return (
      <Popper modifiers={[{ name: 'preventOverflow', options: { altAxis: true } }]}>
        {({ ref, style, placement, arrowProps }) => (
          <div 
            className="tooltip" 
            ref={ref}
            style={style}
            data-placement={placement}
            css={[css`
              visibility: ${this.props.showTooltip ? "visible" : "hidden"};
              width: 250px;
              background: #fff;
              text-align: left;
              padding: 5px;
              border-radius: 6px;
              color: #51565d;
              border: 2px solid rgba(81, 86, 93, 0.1);
              position: absolute;
              z-index: 1;
            `, 
            // @ts-ignore
            this.props.tooltipStyles]}
          >
            <div css={{
              position: "relative",
            }}>
              <div css={css`
                position: absolute;
                right: 2px;
                top: -5px;
                opacity: 0.5;
                font-size: 24px;
                &:hover {
                  cursor: pointer;
                  opacity: 0.9;
                }
              `}
                onClick={this.props.closeTooltip}
              >
                &times;
              </div>
              <h2 className="tooltip-text" css={{marginTop: "0px", paddingTop: "18.675px"}}>{this.props.name}</h2>
              <p className="display-linebreak">
                { this.state.timeDisplay }
              </p>
              {description}
              {location}
              {calendarName}
              <a 
                href={this.state.eventURL}
                target="_blank"
                onMouseDown={e => e.preventDefault()}
                css={{
                  fontSize: "13px",
                  tabIndex: -1
                }}
              >
                Copy to Calendar
              </a>
            </div>
          </div>
        )}    
      </Popper>
      
    );
  }
}
