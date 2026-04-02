// @ts-nocheck

/** Loads google calendar api
 * @param {string} apiKey api key for google's calendar api
 * @return {Promise} resolves when api is successfully loaded and rejects when an error occurs
 */
export function loadCalendarAPI(apiKey: string): Promise<any> {
  return new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = "https://apis.google.com/js/api.js";
    document.body.appendChild(script);
    script.onload = () => {
      gapi.load("client", () => {
        gapi.client.init({ apiKey: apiKey })
          .then(() => {
            gapi.client
              .load(
                "https://content.googleapis.com/discovery/v1/apis/calendar/v3/rest"
              )
              .then(
                () => resolve("GAPI client successfully loaded for API"),
                (err: any) => reject(err)
              );
          });
      });
    }
  })
}

/** query calendar API for events
 * @param {string} calendarId id of the calendar, looks like s9ajkhr604dfrmvm7185lesou0@group.calendar.google.com
 * @param {number} [maxResults=1000] maximum number of events returned, can be up to 2500, currently doesn't support more events
 * @returns {Object} see https://developers.google.com/calendar/v3/reference/events/list for shape of response object
 */
export function getEventsList(calendarId: string, maxResults = 1000): Promise<any> {
  return gapi.client.calendar.events.list({
    calendarId: calendarId,
    maxResults: maxResults,
  });
}
