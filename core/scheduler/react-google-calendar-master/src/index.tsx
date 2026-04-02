/** @jsxImportSource @emotion/react */
import React from "react";

import moment, { Moment } from "moment-timezone";
import { RRule, RRuleSet, rrulestr } from "rrule";

import { CalendarProps, CalendarState } from './types';

import "./index.css";

import Event from "./event";
import MultiEvent from "./multiEvent";

import { isMultiEvent } from "./utils/helper";
import { loadCalendarAPI, getEventsList } from "./utils/googleCalendarAPI";

import { css, jsx } from '@emotion/react'

import _ from "lodash";

//@ts-ignore
import gud from "gud";

import { Languages, availableLanguages } from "./languages";

export default class Calendar extends React.Component<CalendarProps, CalendarState> {
  constructor(props: CalendarProps) {
    super(props);
    this.state = {
      monthNames: [...Languages.EN.MONTHS],
      days: [...Languages.EN.DAYS],
      today: moment(),
      current: moment().startOf("month").utc(true), //current position on calendar (first day of month)
      calendars: [],
      events: [],//all day or multi day events
      singleEvents: [], //single day events
      userTimezone: moment.tz.guess(),
      showFooter: props.showFooter || true,
      showArrow: props.showArrow || true,
      processedCalendars: [],
    };
    
    this.lastMonth = this.lastMonth.bind(this);
    this.nextMonth = this.nextMonth.bind(this);
  }

  async componentDidMount() {
    if (
      this.props.language &&
      availableLanguages.includes(this.props.language.toUpperCase())
    ) {
      // try to change language
      try {
        const lang = this.props.language.toUpperCase();
        this.setState({
          //@ts-ignore
          monthNames: [...Languages[lang].MONTHS],
          //@ts-ignore
          days: [...Languages[lang].DAYS],
        });
      } catch (err) {
        console.error("Error choosing a new language", err);
      }
    }
    //init and load google calendar api
    try {
      const res = await loadCalendarAPI(this.props.apiKey);
    } catch(err) {
      console.error("Error loading GAPI client for API", err);
    }

    //Get events from all calendars
    for (let calendar of this.props.calendars) {
      try {
        //query api for events
        const res = await getEventsList(calendar.calendarId);

        //process events
        //@ts-ignore
        const events = this.processEvents(res.result.items, res.result.summary, calendar.color);

        //fix duplicate calendars
        if (!this.state.processedCalendars.includes(calendar.calendarId)) {
          //set state with calculated values
          this.setState({
            "events": [...this.state.events, ...events[0]], 
            "singleEvents": [...this.state.singleEvents, ...events[1]], 
            "processedCalendars": [...this.state.processedCalendars, calendar.calendarId]
          });
        }
      } catch(err) {
        console.error("Error getting events", err);
      }
    }
  }

  //get easy to work with events and singleEvents from response
  processEvents(items: any[], calendarName: string, color: string): any[] {
    let singleEvents: any[] = [];
    let events: any[] = [];
    let changed: any[] = [];
    let cancelled: any[] = [];

    items.forEach((event) => {
      if (event.originalStartTime) { //cancelled or changed events
        if (event.status == "cancelled") { //cancelled events
          cancelled.push({
            recurringEventId: event.recurringEventId,
            originalStartTime: event.originalStartTime.dateTime ? moment(event.originalStartTime.dateTime) : moment.parseZone(event.originalStartTime.date), 
          });
        } else if (event.status == "confirmed") { //changed events
          changed.push({
            recurringEventId: event.recurringEventId,
            name: event.summary,
            description: event.description,
            location: event.location,
            originalStartTime: event.originalStartTime.dateTime ? moment(event.originalStartTime.dateTime) : moment.parseZone(event.originalStartTime.date),
            newStartTime: event.start.dateTime ? moment(event.start.dateTime) : moment.parseZone(event.start.date),
            newEndTime: event.end.dateTime ? moment(event.end.dateTime) : moment.parseZone(event.end.date),
          });
        } else {
          console.log("Not categorized: ", event);
        }
      } else if (event.status == "confirmed") { //normal events
        let newEvent = {
          id: event.id,
          name: event.summary,
          startTime: event.start.dateTime ? moment(event.start.dateTime) : moment.parseZone(event.start.date),
          endTime: event.end.dateTime ? moment(event.end.dateTime) : moment.parseZone(event.end.date),
          description: event.description,
          location: event.location,
          recurrence: event.recurrence,
          changedEvents: [],
          cancelledEvents: [],
          calendarName: calendarName,
          color: color
        };

        if (isMultiEvent(newEvent.startTime, newEvent.endTime)) {
          events.push(newEvent);
        } else {
          singleEvents.push(newEvent);
        }
      } else {
        console.log("Not categorized: ", event);
      }
    });

    //add changed events and cancelled events to corresponding event object
    events.forEach((event, idx, arr) => {
      if (event.recurrence) {
        //push changed events
        changed.filter(change => change.recurringEventId == event.id).forEach((change) => {
          arr[idx].changedEvents.push(change);
        });

        //push cancelled events
        cancelled.filter(cancel => cancel.recurringEventId == event.id).forEach((cancel) => {
          arr[idx].cancelledEvents.push(cancel.originalStartTime);
        });
      }
    });

    singleEvents.forEach((event, idx, arr) => {
      if (event.recurrence) {
        //push changed events
        changed.filter(change => change.recurringEventId == event.id).forEach((change) => {
          arr[idx].changedEvents.push(change);
        });

        //push cancelled events
        cancelled.filter(cancel => cancel.recurringEventId == event.id).forEach((cancel) => {
          arr[idx].cancelledEvents.push(cancel.originalStartTime);
        });
      }
    });

    return [events, singleEvents];
  }

  //sets current month to previous month
  lastMonth() {
    this.setState({ current: this.state.current.subtract(1, "months") });
  }

  //sets current month to following month
  nextMonth() {
    this.setState({ current: this.state.current.add(1, "months") });
  }

  clearEvents() {
    for (let i = 1; i <= this.state.current.daysInMonth(); i++) {
      const node: any = document.getElementById("day-" + i);
      while (node.lastElementChild) {
        node.removeChild(node.lastElementChild);
      }
    }
  }
  
  //renders the day of week names
  renderDays() {
    return this.state.days.map((x, i) => (
      <div
        className="day-name"
        key={"day-of-week-" + i}
        css={[{ borderColor: "LightGray" }, _.get(this.props.styles, 'day', {})]}
      >
        {x}
      </div>
    ));
  }

  //renders the blocks for the days of each month
  renderDates(eventsEachDay: any[]): any[] {
    //@ts-ignore
    var days = [...Array(this.state.current.daysInMonth() + 1).keys()].slice(1); // create array from 1 to number of days in month

    var dayOfWeek = this.state.current.day(); //get day of week of first day in the month

    var padDays = (((-this.state.current.daysInMonth() - this.state.current.day()) % 7) + 7) % 7; //number of days to fill out the last row    

    return [
      [...Array(dayOfWeek)].map((x, i) => (
        <div
          className="day"
          key={"empty-day-" + i}
          css={_.get(this.props.styles, 'day', {})}
        ></div>
      )),
      days.map(x => {
        if (x == this.state.today.date() && this.state.current.isSame(this.state.today, "month")) {
          return (
            <div
              className="day"
              key={"day-" + x}
              css={[_.get(this.props.styles, 'day', {}), _.get(this.props.styles, 'today', {})]}
            >
              <span
                css={{
                  paddingRight: '6px',
                }}
              >
                {x}
              </span>
              <div className="innerDay" id={"day-" + x}>{eventsEachDay[x - 1]}</div>
            </div>
          );
        } else {
          return (
            <div
              className="day"
              key={"day-" + x}
              css={_.get(this.props.styles, 'day', {})}
            >
              <span
                css={{
                  paddingRight: '6px',
                }}
              >
                {x}
              </span>
              <div className="innerDay" id={"day-" + x}>{eventsEachDay[x - 1]}</div>
            </div>
          );
        }
      }),
      [...Array(padDays)].map((x, i) => (
        <div
          className="day"
          key={"empty-day-2-" + i}
          css={_.get(this.props.styles, 'day', {})}
        ></div>
      ))
    ];
  }

  //get array of arrays of length days in month containing the events in each day
  getRenderEvents(events: any[], singleEvents: any[]) {
    let eventsEachDay = [...Array(this.state.current.daysInMonth())].map((e) => []); //create array of empty arrays of length daysInMonth
    let eventsToRender: any[] = [];
    events.forEach((event) => {
      if (event.recurrence) {
        let duration = moment.duration(event.endTime.diff(event.startTime));
        let dates = Calendar.getDatesFromRRule(event.recurrence[0], event.startTime, moment(this.state.current).subtract(duration), moment(this.state.current).add(1, "month"));

        //render recurrences
        dates.forEach((date) => {
          //don't render if it is cancelled
          if (event.cancelledEvents.some((cancelledMoment: any) => (cancelledMoment.isSame(date, "day")))) {
            return;
          }

          let props;
          //update information if event has changed
          const changedEvent = event.changedEvents.find((changedEvent: any) => (changedEvent.originalStartTime.isSame(date, "day")));
          if (changedEvent) {
            props = {
              name: changedEvent.name,
              startTime: changedEvent.newStartTime,
              endTime: changedEvent.newEndTime,
              description: changedEvent.description,
              location: changedEvent.location,
              calendarName: event.calendarName,
              color: event.color
            }
          } else {
            let eventStart = moment.utc(date); //since rrule works with utc times
            let eventEnd = moment(eventStart).add(duration);
            props = {
              name: event.name,
              startTime: eventStart,
              endTime: eventEnd,
              description: event.description,
              location: event.location,
              calendarName: event.calendarName,
              color: event.color
            };
          }
          eventsToRender.push(props);
        });
      } else {
        //render event
        //check if event is in range
        if ((event.startTime.month() != this.state.current.month() || event.startTime.year() != this.state.current.year()) &&
        event.endTime.month() != this.state.current.month() || event.endTime.year() != this.state.current.year()
        ) {
          return;
        }
        eventsToRender.push(event);
      }
    });

    eventsToRender = eventsToRender.sort((a, b) => {
      if (a.startTime.diff(b.startTime) != 0) {
        return a.startTime.diff(b.startTime);
      } else {
        return b.endTime.diff(a.endTime);
      }
    })

    eventsToRender.forEach((event) => {
      this.drawMultiEvent(eventsEachDay, event);
    });

    let eventProps = {
      tooltipStyles: _.get(this.props.styles, 'tooltip', {}), //gets this.props.styles.tooltip if exists, else empty object
      eventStyles: _.get(this.props.styles, 'event', {}),
      eventCircleStyles: _.get(this.props.styles, 'eventCircle', {}),
      eventTextStyles: _.get(this.props.styles, 'eventText', {}),
    }

    let singleEventsToRender: any[] = [];
    singleEvents.forEach((event) => {
      if (event.recurrence) {
        let duration = moment.duration(event.endTime.diff(event.startTime));
        
        //get recurrences using RRule
        let dates = Calendar.getDatesFromRRule(event.recurrence[0], event.startTime, moment(this.state.current), moment(this.state.current).add(1, "month"));

        //render recurrences
        dates.forEach((date) => {
          //check if it is in cancelled
          if (event.cancelledEvents.some((cancelledMoment: any) => (cancelledMoment.isSame(date, "day")))) {
            return;
          }

          //if event has changed
          const changedEvent = event.changedEvents.find((changedEvent: any) => (changedEvent.originalStartTime.isSame(date, "day")));
          let props;
          if (changedEvent) {
            props = {
              name: changedEvent.name,
              startTime: changedEvent.newStartTime,
              endTime: changedEvent.newEndTime,
              description: changedEvent.description,
              location: changedEvent.location,
              calendarName: event.calendarName,
              color: event.color
            }
          } else {
            let eventStart = moment.utc(date); //avoid bad timezone conversions
            let eventEnd = moment(eventStart).add(duration);
            props = {
              name: event.name,
              startTime: eventStart,
              endTime: eventEnd,
              description: event.description,
              location: event.location,
              calendarName: event.calendarName,
              color: event.color
            };
          }
          
          singleEventsToRender.push(props);
        });
      } else {
        //check if event is in current month
        if (event.startTime.month() != this.state.current.month() || event.startTime.year() != this.state.current.year()) {
          return;
        }

        singleEventsToRender.push(event);
      }
    });

    singleEventsToRender = singleEventsToRender.sort((a, b) => (a.startTime.diff(b.startTime)));
    singleEventsToRender.forEach((event) => {
      this.renderSingleEvent(eventsEachDay, moment(event.startTime).date(), {...event, ...eventProps});
    });

    return eventsEachDay;
  }

  //TODO: refactor
  //decides how to render events
  drawMultiEvent(eventsEachDay: any[], props: any) { 
    let startDrawDate;
    let blockLength = 1;
    let curDate;
    let endDate;

    let arrowLeft = false;
    let arrowRight = false;

    if (props.endTime.isSame(moment(props.endTime).startOf("day"), "second")) {
      endDate = moment(props.endTime).utc(true).subtract(1, "day");
    } else {
      endDate = moment(props.endTime).utc(true);
    }

    if (moment(props.startTime).utc(true).isBefore(this.state.current)) {
      if (this.state.showArrow) {
        arrowLeft = true;
      }
      
      startDrawDate = 1;
      curDate = moment(this.state.current).utc(true);
    } else {
      startDrawDate = props.startTime.date();
      curDate = moment(props.startTime).utc(true);
    }

    while (curDate.isSameOrBefore(endDate, "day")) {
      if (curDate.date() == this.state.current.daysInMonth() && !endDate.isSame(this.state.current, 'month')) {
        if (this.state.showArrow) {
          arrowRight = true;
        }
        
        //draw then quit
        this.renderMultiEventBlock(eventsEachDay, startDrawDate, blockLength, props, arrowLeft, arrowRight);
        break;
      }
      if (curDate.date() == this.state.current.daysInMonth() || curDate.isSame(endDate, "day")) {
        //draw then quit
        this.renderMultiEventBlock(eventsEachDay, startDrawDate, blockLength, props, arrowLeft, arrowRight);
        break;
      }
      if (curDate.day() == 6) {
        //draw then reset
        this.renderMultiEventBlock(eventsEachDay, startDrawDate, blockLength, props, arrowLeft, arrowRight);
        startDrawDate = moment(curDate).add(1, "day").date();
        blockLength = 0;
        arrowLeft = false;
        arrowRight = false;
      }

      blockLength++;
      curDate.add(1, "day");
    }
  }

  //TODO: refactor this too?
  //handles rendering and proper stacking of individual blocks 
  renderMultiEventBlock(eventsEachDay: any[], startDate: any, length: number, props: any, arrowLeft: boolean, arrowRight: boolean) { 
    let multiEventProps = {
      tooltipStyles: _.get(this.props.styles, 'tooltip', {}), //gets this.props.styles.tooltip if exists, else empty object
      multiEventStyles: _.get(this.props.styles, 'multiEvent', {}),
    }

    let maxBlocks = 0;
    let closedSlots: any[] = []; //keep track of rows that the event can't be inserted into

    for (let i = 0; i < length; i++) {
      let dayEvents = eventsEachDay[startDate - 1 + i];
      if (dayEvents.length > maxBlocks) {
        maxBlocks = dayEvents.length;
      }

      //address rows that are not the last element in closedSlots
      for (let j = 0; j < maxBlocks; j++) {
        if (j > dayEvents.length) {
          break;
        } else if (closedSlots.includes(j)) {
          continue;
        } 
        if (dayEvents[j].props.className.includes("isEvent")) {
          closedSlots.push(j);
        }
      }
    }

    let chosenRow = -1;
    for (let i = 0; i <= maxBlocks; i++) {
      if (!closedSlots.includes(i)) {
        chosenRow = i;
        break;
      }
    }

    //fill in placeholders
    for (let i = 0; i < length; i++) {
      //placeholders
      while (eventsEachDay[startDate - 1 + i].length <= chosenRow) {
        eventsEachDay[startDate - 1 + i].push(<div className="event below placeholder" key={`placeholder-${gud()}`}></div>);
      }

      //rest of event that is under the main banner
      eventsEachDay[startDate - 1 + i][chosenRow] = <div className="isEvent event below" key={`filler-${gud()}`}></div>;
    }
  
    //render event
    eventsEachDay[startDate - 1][chosenRow] = <div className="isEvent" key={`multi-event-${chosenRow}`}><MultiEvent {...props} {...multiEventProps} length={length} arrowLeft={arrowLeft} arrowRight={arrowRight} key={`multi-event-${gud()}`}/></div>;
  }

  //attempts to render in a placeholder then at the end
  renderSingleEvent(eventsEachDay: any[], date: number, props: any) {
    let foundEmpty = false;
    let nodes = eventsEachDay[date - 1];
    for (let i = 0; i < nodes.length; i++) {
      if (nodes[i].props.className.includes("event") && !nodes[i].props.className.includes("isEvent")) { //target only placeholders
        nodes[i] = <div className="isEvent" key={`single-event-${gud()}`}><Event {...props} key={`single-event-${gud()}`}/></div>;
        foundEmpty = true;
        break;
      }
    }
    if (!foundEmpty) {
      eventsEachDay[date - 1].push(<div className="isEvent" key={`single-event-${gud()}`}><Event {...props} key={`single-event-${gud()}`}/></div>)
    }
  }

  //get dates based on rrule string between dates
  static getDatesFromRRule(str: string, eventStart: Moment, betweenStart: Moment, betweenEnd: Moment) {    
    //get recurrences using RRule
    let rstr = `DTSTART:${moment(eventStart).utc(true).format('YYYYMMDDTHHmmss')}Z\n${str}`;
    let rruleSet = rrulestr(rstr, {forceset: true});
    
    //get dates
    let begin = moment(betweenStart).utc(true).toDate();
    let end = moment(betweenEnd).utc(true).toDate();
    let dates = rruleSet.between(begin, end);
    return dates;
  }

  render() {
    let eventsEachDay = this.getRenderEvents(this.state.events, this.state.singleEvents);
    return (
      <div
        className="calendar"
        css={[{
          fontSize: "18px",
          border: "1px solid",
          minWidth: "300px",
          position: "relative",
          borderColor: "LightGray",
          color: "#51565d",
        }, _.get(this.props.styles, 'calendar', {})]}
      >
        <div className="calendar-header">
          <div
            className="calendar-navigate unselectable"
            onClick={this.lastMonth}
          >
            &#10094;
          </div>
          <div>
            <h2 className="calendar-title">
              {this.state.monthNames[this.state.current.month()] + " " + this.state.current.year()}
            </h2>
          </div>
          <div
            className="calendar-navigate unselectable"
            onClick={this.nextMonth}
          >
            &#10095;
          </div>
        </div>
        <div className="calendar-body">
          {this.renderDays()}
          {this.renderDates(eventsEachDay)}
        </div>
        { this.state.showFooter && 
          <div className="calendar-footer">
            <div css={css`
              font-size: 14px;
              padding-left: 5px;
              text-align: left;
            `}>
              All times shown your timezone ({moment().tz(this.state.userTimezone).format("z")})
            </div>
            <div css={css`
              vertical-align: top;
              margin-left: 3px;
              margin-right: 3px;
            `}>
              <a href={"https://calendar.google.com/calendar/r?cid=" + this.props.calendars[0].calendarId} target="_blank" css={css`
                font-size: 14px;
                text-decoration: none;
                color: inherit;
                &:hover {
                  text-decoration: underline;
                }
              `}>
                &#43; Add Calendar
              </a>
            </div>
          </div>
        }
        
      </div>
    );
  }
}
