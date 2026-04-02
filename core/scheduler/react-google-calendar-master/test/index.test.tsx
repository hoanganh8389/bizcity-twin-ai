/**
 * @jest-environment jsdom
 */

import { describe, test, expect, beforeAll, afterAll } from "vitest"; 

import React from "react";
import { createRoot } from "react-dom/client";
import { act } from "@testing-library/react";

import Calendar from "../src/index";
import moment from "moment-timezone";

describe("simple end to end test", () => {
  test("test full render", () => {
    let container = document.createElement("div");
    document.body.appendChild(container);
    let root = createRoot(container);
    let calendars = [
      {calendarId: "09opmkrjova8h5k5k46fedmo88@group.calendar.google.com"}
    ]

    act(() => {
      // @ts-ignore
      root.render(<Calendar apiKey={process.env.GOOGLE_API_KEY} calendars={calendars} />);
    });
  })
})

describe("Use rrule to get recurrences", () => {
  test("proper amount of events", () => {
    let recurrenceString = "RRULE:FREQ=WEEKLY;WKST=SU;COUNT=5;BYDAY=SU"
    let eventStart = moment.parseZone("2020-05-31 17:00");
    let betweenStart = moment.parseZone("2020-06-01");
    let betweenEnd = moment.parseZone("2020-07-01");
    let dates = Calendar.getDatesFromRRule(recurrenceString, eventStart, betweenStart, betweenEnd);
    expect(dates.length).toBe(4);
  });

  test("proper amount of events 2", () => {
    let recurrenceString = "RRULE:FREQ=WEEKLY;WKST=SU;COUNT=5;BYDAY=SU"
    let eventStart = moment.parseZone("2020-05-31");
    let betweenStart = moment.parseZone("2020-05-01");
    let betweenEnd = moment.parseZone("2020-06-01");
    let dates = Calendar.getDatesFromRRule(recurrenceString, eventStart, betweenStart, betweenEnd);
    expect(dates.length).toBe(1);
  });

  test("all day that ends exactly at the beginning of the next month", () => {
    let recurrenceString = "RRULE:FREQ=WEEKLY;BYDAY=TH"
    let eventStart = moment.parseZone("2020-05-29");
    let betweenStart = moment.parseZone("2020-07-01").subtract(1, "day");
    let betweenEnd = moment.parseZone("2020-08-01");
    let dates = Calendar.getDatesFromRRule(recurrenceString, eventStart, betweenStart, betweenEnd);
    expect(dates.length).toBe(5);
  });

  test("all day that ends exactly at the beginning of the next month 2", () => {
    let recurrenceString = "RRULE:FREQ=WEEKLY;BYDAY=TH"
    let eventStart = moment.parseZone("2020-05-31");
    let betweenStart = moment.parseZone("2020-08-01").subtract(1, "day");
    let betweenEnd = moment.parseZone("2020-09-01");
    let dates = Calendar.getDatesFromRRule(recurrenceString, eventStart, betweenStart, betweenEnd);
    expect(dates.length).toBe(4);
  });

  test("Doesn't break when exdate is passed in", () => {
    let recurrenceString = "EXDATE;TZID=America/New_York:20181011T120000"
    let eventStart = moment.parseZone("2020-05-31");
    let betweenStart = moment.parseZone("2020-08-01").subtract(1, "day");
    let betweenEnd = moment.parseZone("2020-09-01");
    Calendar.getDatesFromRRule(recurrenceString, eventStart, betweenStart, betweenEnd);
  });
});
