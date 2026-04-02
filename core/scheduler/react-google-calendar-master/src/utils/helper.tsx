// @ts-nocheck
import moment, { Moment } from "moment-timezone";

/** determines whether event is an all day event
  * @param {moment} startTime start of event
  * @param {moment} endTime end of event
  * @return {boolean} Whether or not it is an all day event
  */
export function isAllDay(startTime: Moment, endTime: Moment): boolean {
  return startTime.isSame(moment.parseZone(startTime).startOf("day"), "second")
    && endTime.isSame(moment.parseZone(endTime).startOf("day"), "second");
}

/** get google calendar link to copy event
  * @param {moment} startTime start of event
  * @param {moment} endTime end of event
  * @param {string} name name of event
  * @param {string} description description of event
  * @param {string} location location of event
  * @param {boolean} allDay whether or not it is an all day event
  * @return {string} url of the link
  */
export function getCalendarURL(startTime: Moment, endTime: Moment, 
  name: string, description?: string, location?: string, allDay?: boolean): string {
  const url = new URL("https://calendar.google.com/calendar/r/eventedit");
  url.searchParams.append("text", name || "");
  
  if (allDay) {
    url.searchParams.append("dates", startTime.format("YYYYMMDD") + "/" + endTime.format("YYYYMMDD"));
  } else {
    url.searchParams.append("dates", startTime.format("YYYYMMDDTHHmmss") + "/" + endTime.format("YYYYMMDDTHHmmss"));
  }
  
  url.searchParams.append("details", description || "");
  url.searchParams.append("location", location || "");
  return url.href;
}

/** determines whether or not it is rendered as a single event or multi event (based on google calendar way)
 * true if duration is at least 24 hours or ends after 12pm on the next day
 * @param {moment} startTime 
 * @param {moment} endTime 
 * @return {boolean} whether or not it is a single event
 */
export function isMultiEvent(startTime: Moment, endTime: Moment): boolean {
  return moment.duration(endTime.diff(startTime)).asHours() >= 24 || (!startTime.isSame(endTime, 'day') && endTime.hour() >= 12)
}

//function to shade colors
//modified from https://github.com/PimpTrizkit/PJs/wiki/12.-Shade,-Blend-and-Convert-a-Web-Color-(pSBC.js)#stackoverflow-archive-begin
export function pSBC(p,c0,c1?,l?): string {
  let r,g,b,P,f,t,h,i=parseInt,m=Math.round,a=typeof(c1)=="string";
  if(typeof(p)!="number"||p<-1||p>1||typeof(c0)!="string"||(c0[0]!='r'&&c0[0]!='#')||(c1&&!a))return null;
  
  function pSBCr(d){
    let n=d.length,x={};
    if(n>9){
        [r,g,b,a]=d=d.split(","),n=d.length;
        if(n<3||n>4)return null;
        x.r=i(r[3]=="a"?r.slice(5):r.slice(4)),x.g=i(g),x.b=i(b),x.a=a?parseFloat(a):-1
    }else{
        if(n==8||n==6||n<4)return null;
        if(n<6)d="#"+d[1]+d[1]+d[2]+d[2]+d[3]+d[3]+(n>4?d[4]+d[4]:"");
        d=i(d.slice(1),16);
        if(n==9||n==5)x.r=d>>24&255,x.g=d>>16&255,x.b=d>>8&255,x.a=m((d&255)/0.255)/1000;
        else x.r=d>>16,x.g=d>>8&255,x.b=d&255,x.a=-1
    }return x};
    
  h=c0.length>9,h=a?c1.length>9?true:c1=="c"?!h:false:h,f=pSBCr(c0),P=p<0,t=c1&&c1!="c"?pSBCr(c1):P?{r:0,g:0,b:0,a:-1}:{r:255,g:255,b:255,a:-1},p=P?p*-1:p,P=1-p;
  if(!f||!t)return null;
  if(l)r=m(P*f.r+p*t.r),g=m(P*f.g+p*t.g),b=m(P*f.b+p*t.b);
  else r=m((P*f.r**2+p*t.r**2)**0.5),g=m((P*f.g**2+p*t.g**2)**0.5),b=m((P*f.b**2+p*t.b**2)**0.5);
  a=f.a,t=t.a,f=a>=0||t>=0,a=f?a<0?t:t<0?a:a*P+t*p:0;
  if(h)return"rgb"+(f?"a(":"(")+r+","+g+","+b+(f?","+m(a*1000)/1000:"")+")";
  else return"#"+(4294967296+r*16777216+g*65536+b*256+(f?m(a*255):0)).toString(16).slice(1,f?undefined:-2)
}
