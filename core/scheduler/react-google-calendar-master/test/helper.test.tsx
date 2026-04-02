import { describe, test, expect } from "vitest";

import { isAllDay, getCalendarURL, isMultiEvent, pSBC } from "../src/utils/helper";
import moment from "moment-timezone";


describe("isAllDay function", () => {
  test("single day all day event", () => {
    let startTime = moment.parseZone("2020-06-09");
    let endTime = moment.parseZone("2020-06-10");
    expect(isAllDay(startTime, endTime)).toBe(true);
  });

  test("multiple day all day event", () => {
    let startTime = moment.parseZone("2020-06-09");
    let endTime = moment.parseZone("2020-06-25");
    expect(isAllDay(startTime, endTime)).toBe(true);
  });

  test("not all day event", () => {
    let startTime = moment.parseZone("2020-06-09");
    let endTime = moment.parseZone("2020-06-25 05:30");
    expect(isAllDay(startTime, endTime)).toBe(false);
  });

  test("not all day event", () => {
    let startTime = moment.parseZone("2020-06-09 05:30");
    let endTime = moment.parseZone("2020-06-25");
    expect(isAllDay(startTime, endTime)).toBe(false);
  });

  test("not all day event", () => {
    let startTime = moment.parseZone("2020-06-09 05:30");
    let endTime = moment.parseZone("2020-06-25 05:30");
    expect(isAllDay(startTime, endTime)).toBe(false);
  });
});

describe("getCalendarURL function", () => {
  test("correct all day event url", () => {
    const props = {
      startTime: moment.parseZone("2020-05-01"),
      endTime: moment.parseZone("2020-05-02"),
      name: "Event",
      description: "Some Description",
      location: "A Location",
      allDay: true,
    }
    const outputURL = getCalendarURL(props.startTime, props.endTime, props.name, props.description, props.location, props.allDay);

    const expectedURL = "https://calendar.google.com/calendar/r/eventedit?text=Event&dates=20200501%2F20200502&details=Some+Description&location=A+Location";
    expect(outputURL).toBe(expectedURL);
  });

  test("correct multi day non all day event url", () => {
    const props = {
      startTime: moment.parseZone("2020-05-02 16:30"),
      endTime: moment.parseZone("2020-05-04 17:30"),
      name: "Event",
      description: "Some Description",
      location: "A Location",
      allDay: false,
    }
    const outputURL = getCalendarURL(props.startTime, props.endTime, props.name, props.description, props.location, props.allDay);

    const expectedURL = "https://calendar.google.com/calendar/r/eventedit?text=Event&dates=20200502T163000%2F20200504T173000&details=Some+Description&location=A+Location";
    expect(outputURL).toBe(expectedURL);
  });

  test("correct single day non all day event url", () => {
    const props = {
      startTime: moment.parseZone("2020-05-02 16:30"),
      endTime: moment.parseZone("2020-05-02 17:30"),
      name: "Event",
      description: "Some Description",
      location: "A Location",
    };

    const outputURL = getCalendarURL(props.startTime, props.endTime, props.name, props.description, props.location);

    const expectedURL = "https://calendar.google.com/calendar/r/eventedit?text=Event&dates=20200502T163000%2F20200502T173000&details=Some+Description&location=A+Location";
    expect(outputURL).toBe(expectedURL);
  });

  test("no description", () => {
    const props = {
      startTime: moment.parseZone("2020-05-02 16:30"),
      endTime: moment.parseZone("2020-05-02 17:30"),
      name: "Event",
      location: "A Location",
    };

    //@ts-ignore
    const outputURL = getCalendarURL(props.startTime, props.endTime, props.name, props.description, props.location);

    const expectedURL = "https://calendar.google.com/calendar/r/eventedit?text=Event&dates=20200502T163000%2F20200502T173000&details=&location=A+Location";
    expect(outputURL).toBe(expectedURL);
  });

  test("no location", () => {
    const props = {
      startTime: moment.parseZone("2020-05-02 16:30"),
      endTime: moment.parseZone("2020-05-02 17:30"),
      description: "Some Description",
      name: "Event",
    };

    //@ts-ignore
    const outputURL = getCalendarURL(props.startTime, props.endTime, props.name, props.description, props.location);

    const expectedURL = "https://calendar.google.com/calendar/r/eventedit?text=Event&dates=20200502T163000%2F20200502T173000&details=Some+Description&location=";
    expect(outputURL).toBe(expectedURL);
  });
});

describe("isMultiEvent function", () => {
  test("same day less than 24 hrs", () => {
    let startTime = moment.parseZone("2020-06-01 04:30");
    let endTime = moment.parseZone("2020-06-01 15:30");

    expect(isMultiEvent(startTime, endTime)).toBe(false);
  });

  test("multi day but short event", () => {
    let startTime = moment.parseZone("2020-06-01 15:30");
    let endTime = moment.parseZone("2020-06-02 04:30");

    expect(isMultiEvent(startTime, endTime)).toBe(false);
  });

  test("all day event", () => {
    let startTime = moment.parseZone("2020-06-01");
    let endTime = moment.parseZone("2020-06-02");

    expect(isMultiEvent(startTime, endTime)).toBe(true);
  });

  test("multi day event", () => {
    let startTime = moment.parseZone("2020-06-01 04:30");
    let endTime = moment.parseZone("2020-06-02 15:30");

    expect(isMultiEvent(startTime, endTime)).toBe(true);
  });

  test("multi day all day event", () => {
    let startTime = moment.parseZone("2020-06-01");
    let endTime = moment.parseZone("2020-06-05");

    expect(isMultiEvent(startTime, endTime)).toBe(true);
  });
});

describe("pSBC function", () => {
  test("hex code", () => {
    expect(pSBC(-0.35, '#4786ff')).toEqual('#396cce');
  });

  test("rgb", () => {
    expect(pSBC(-0.35, 'rgb(63, 191, 63)')).toEqual('rgb(51,154,51)');
  });
});
