/**
 * @jest-environment jsdom
 */

import { describe, test, expect, beforeAll, afterAll } from "vitest"; 

import React from "react";
import { createRoot } from "react-dom/client";
import { act } from "@testing-library/react";
import moment from "moment-timezone";

import Event from "../src/event";

let basicProps = {
  borderColor: "black",
  hoverColor: "red",
  textColor: "blue",
  circleColor: "green",
}

let container;
let root;

beforeAll(() => {
  container = document.createElement("div");
  document.body.appendChild(container);
  root = createRoot(container);
});

afterAll(() => {
  document.body.removeChild(container);
  container = null;
  root.unmount();
});

describe("Event Component", () => {
  test("opens and closes properly on click",() => {
    let props = {
      name: "Test Event",
      startTime: moment(),
      endTime: moment(),
    };

    act(() => {
      root.render(<Event {...basicProps} {...props} />);
    });

    expect(window.getComputedStyle(container.querySelector('.tooltip')).visibility).toEqual("hidden");
    
    act(() => {
      container.querySelector(".event-text").dispatchEvent(new MouseEvent("click", { bubbles: true }));
    });

    expect(window.getComputedStyle(container.querySelector('.tooltip')).visibility).toEqual("visible");

    act(() => {
      container.querySelector(".event-text").dispatchEvent(new MouseEvent("click", { bubbles: true }));
    });

    expect(window.getComputedStyle(container.querySelector('.tooltip')).visibility).toEqual("hidden");
  });
});
