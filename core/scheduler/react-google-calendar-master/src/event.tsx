/** @jsxImportSource @emotion/react */

import React from "react";

import moment from "moment-timezone";

import "./index.css";

import { css, jsx } from '@emotion/react'

import FiberManualRecordIcon from "./svg/fiberManualRecord";

import Tooltip from "./tooltip";

import { Manager, Reference } from 'react-popper';

import { EventProps, EventState } from './types/event';

export default class Event extends React.Component<EventProps, EventState> {
  constructor(props: EventProps) {
    super(props);

    this.state = {      
      startTime: moment(this.props.startTime),
      endTime: moment(this.props.endTime),
      showTooltip: false,
    }

    this.toggleTooltip = this.toggleTooltip.bind(this);
    this.closeTooltip = this.closeTooltip.bind(this);
  }

  closeTooltip() {
    this.setState({showTooltip: false});
  }

  toggleTooltip() {
    this.setState({showTooltip: !this.state.showTooltip});
  }

  render() {
    return (
      <Manager>
        <div 
          className="event"
          // @ts-ignore
          tabIndex="0"
          onBlur={this.closeTooltip}
          css={css`
            position: relative;
            &:focus {
              outline: none;
            }
          `}
        >
          <Reference>
            {({ref}) => (
              <div css={[css`
                  border-radius: 3px;
                  width: 100%;
                  &:hover {
                    cursor: pointer;
                    background: rgba(81, 86, 93, 0.1);
                  }
                `, 
                // @ts-ignore
                this.props.eventStyles]}
                onClick={this.toggleTooltip}
                ref={ref}
              >
                <div 
                  className="event-text" 
                  css={[{
                    color: "#51565d",
                    padding: "3px 0px 3px 20px",
                    marginRight: "5px",
                    overflowX: "hidden",
                    whiteSpace: "nowrap",
                    position: "relative",
                    textAlign: "left",
                  }, 
                  // @ts-ignore
                  this.props.eventTextStyles]}
                >
                  <span css={[{
                    position: "absolute",
                    top: "5px",
                    left: "2px",
                    color: this.props.color || "#4786ff",
                    height: "15px",
                    width: "15px",
                  }, 
                  // @ts-ignore
                  this.props.eventCircleStyles]}>
                    <FiberManualRecordIcon fill="currentColor" fontSize="inherit" width="100%" />
                  </span>
                  <span css={css`
                    @media (max-width: 599px) {
                      display: none;
                    }
                  `}>
                    { this.state.startTime.format("h:mma ") }
                  </span>
                  <span css={{fontWeight: "500"}}>
                    {this.props.name}
                  </span>
                </div>
              </div>
            )}
          </Reference>
          <Tooltip
            name={this.props.name}
            startTime={moment(this.props.startTime)}
            endTime={moment(this.props.endTime)}
            description={this.props.description}
            location={this.props.location}
            tooltipStyles={this.props.tooltipStyles}
            showTooltip={this.state.showTooltip}
            closeTooltip={this.closeTooltip}
            calendarName={this.props.calendarName}
          />
        </div>
      </Manager>
    )
  }
}
