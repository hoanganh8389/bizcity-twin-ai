/**
 * @jest-environment jsdom
 */

import { describe, test, expect, beforeEach, afterEach } from "vitest"; 

import React from "react";
import { createRoot } from "react-dom/client";
import { act } from "@testing-library/react";

import Calendar from "../src/index";

let container;
let root;

beforeEach(() => {
  container = document.createElement("div");
  document.body.appendChild(container);
  root = createRoot(container);
});

afterEach(() => {
  root.unmount();
});

describe("check if invalid language affects component", () => {
  test("test invalid language", () => {
    container = document.createElement("div");
    document.body.appendChild(container);
    root = createRoot(container);
    const calendars = [
      { calendarId: "09opmkrjova8h5k5k46fedmo88@group.calendar.google.com" },
    ];

    act(() => {
      root.render(
        <Calendar
          // @ts-ignore
          apiKey={process.env.GOOGLE_API_KEY}
          language={"ZZ"}
          calendars={calendars}
        />
      );
    });

    const txt = container.innerHTML;

    expect(txt.search("Mon")).toBeGreaterThan(0);
    document.body.removeChild(container);
    container = null;
    root.unmount();
  });
});

describe("check if the language change affects the component", () => {
  test("test valid language", () => {

    const calendars = [
      { calendarId: "09opmkrjova8h5k5k46fedmo88@group.calendar.google.com" },
    ];

    act(() => {
      root.render(
        <Calendar
          // @ts-ignore
          apiKey={process.env.GOOGLE_API_KEY}
          language={"FR"}
          calendars={calendars}
        />
      );
    });

    const txt = container.innerHTML;

    expect(txt.search("Dim")).toBeGreaterThan(0);
  });
});
