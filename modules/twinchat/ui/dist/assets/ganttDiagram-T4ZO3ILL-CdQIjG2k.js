import{g as de,s as fe,q as he,p as me,a as ke,b as ye,c,d as ut,e as ge,ay as X,l as nt,az as ve,aA as pe,k as Te,j as xe,z as be,u as we}from"./vendor-mermaid-CMiqNC-m.js";import{c as _t,g as Dt}from"./vendor-query-CmcMXlZA.js";import{s as pt,t as _e,p as De,q as Se,l as Ce,w as Me,x as Ht,y as Bt,z as Ee,A as Ie,B as $e,C as Ye,D as Le,E as Ae,F as Fe,G as Gt,H as jt,I as Xt,J as qt,K as Ut}from"./vendor-graph-C2U_okeF.js";var Jt={exports:{}};(function(t,r){(function(n,i){t.exports=i()})(_t,function(){var n="day";return function(i,a,k){var y=function(A){return A.add(4-A.isoWeekday(),n)},_=a.prototype;_.isoWeekYear=function(){return y(this).year()},_.isoWeek=function(A){if(!this.$utils().u(A))return this.add(7*(A-this.isoWeek()),n);var b,F,V,z,R=y(this),M=(b=this.isoWeekYear(),F=this.$u,V=(F?k.utc:k)().year(b).startOf("year"),z=4-V.isoWeekday(),V.isoWeekday()>4&&(z+=7),V.add(z,n));return R.diff(M,"week")+1},_.isoWeekday=function(A){return this.$utils().u(A)?this.day()||7:this.day(this.day()%7?A:A-7)};var O=_.startOf;_.startOf=function(A,b){var F=this.$utils(),V=!!F.u(b)||b;return F.p(A)==="isoweek"?V?this.date(this.date()-(this.isoWeekday()-1)).startOf("day"):this.date(this.date()-1-(this.isoWeekday()-1)+7).endOf("day"):O.bind(this)(A,b)}}})})(Jt);var Oe=Jt.exports;const We=Dt(Oe);var te={exports:{}};(function(t,r){(function(n,i){t.exports=i()})(_t,function(){var n={LTS:"h:mm:ss A",LT:"h:mm A",L:"MM/DD/YYYY",LL:"MMMM D, YYYY",LLL:"MMMM D, YYYY h:mm A",LLLL:"dddd, MMMM D, YYYY h:mm A"},i=/(\[[^[]*\])|([-_:/.,()\s]+)|(A|a|Q|YYYY|YY?|ww?|MM?M?M?|Do|DD?|hh?|HH?|mm?|ss?|S{1,3}|z|ZZ?)/g,a=/\d/,k=/\d\d/,y=/\d\d?/,_=/\d*[^-_:/,()\s\d]+/,O={},A=function(D){return(D=+D)+(D>68?1900:2e3)},b=function(D){return function(S){this[D]=+S}},F=[/[+-]\d\d:?(\d\d)?|Z/,function(D){(this.zone||(this.zone={})).offset=function(S){if(!S||S==="Z")return 0;var W=S.match(/([+-]|\d\d)/g),Y=60*W[1]+(+W[2]||0);return Y===0?0:W[0]==="+"?-Y:Y}(D)}],V=function(D){var S=O[D];return S&&(S.indexOf?S:S.s.concat(S.f))},z=function(D,S){var W,Y=O.meridiem;if(Y){for(var N=1;N<=24;N+=1)if(D.indexOf(Y(N,0,S))>-1){W=N>12;break}}else W=D===(S?"pm":"PM");return W},R={A:[_,function(D){this.afternoon=z(D,!1)}],a:[_,function(D){this.afternoon=z(D,!0)}],Q:[a,function(D){this.month=3*(D-1)+1}],S:[a,function(D){this.milliseconds=100*+D}],SS:[k,function(D){this.milliseconds=10*+D}],SSS:[/\d{3}/,function(D){this.milliseconds=+D}],s:[y,b("seconds")],ss:[y,b("seconds")],m:[y,b("minutes")],mm:[y,b("minutes")],H:[y,b("hours")],h:[y,b("hours")],HH:[y,b("hours")],hh:[y,b("hours")],D:[y,b("day")],DD:[k,b("day")],Do:[_,function(D){var S=O.ordinal,W=D.match(/\d+/);if(this.day=W[0],S)for(var Y=1;Y<=31;Y+=1)S(Y).replace(/\[|\]/g,"")===D&&(this.day=Y)}],w:[y,b("week")],ww:[k,b("week")],M:[y,b("month")],MM:[k,b("month")],MMM:[_,function(D){var S=V("months"),W=(V("monthsShort")||S.map(function(Y){return Y.slice(0,3)})).indexOf(D)+1;if(W<1)throw new Error;this.month=W%12||W}],MMMM:[_,function(D){var S=V("months").indexOf(D)+1;if(S<1)throw new Error;this.month=S%12||S}],Y:[/[+-]?\d+/,b("year")],YY:[k,function(D){this.year=A(D)}],YYYY:[/\d{4}/,b("year")],Z:F,ZZ:F};function M(D){var S,W;S=D,W=O&&O.formats;for(var Y=(D=S.replace(/(\[[^\]]+])|(LTS?|l{1,4}|L{1,4})/g,function(p,v,g){var f=g&&g.toUpperCase();return v||W[g]||n[g]||W[f].replace(/(\[[^\]]+])|(MMMM|MM|DD|dddd)/g,function(o,l,h){return l||h.slice(1)})})).match(i),N=Y.length,G=0;G<N;G+=1){var E=Y[G],T=R[E],d=T&&T[0],u=T&&T[1];Y[G]=u?{regex:d,parser:u}:E.replace(/^\[|\]$/g,"")}return function(p){for(var v={},g=0,f=0;g<N;g+=1){var o=Y[g];if(typeof o=="string")f+=o.length;else{var l=o.regex,h=o.parser,m=p.slice(f),x=l.exec(m)[0];h.call(v,x),p=p.replace(x,"")}}return function(s){var P=s.afternoon;if(P!==void 0){var e=s.hours;P?e<12&&(s.hours+=12):e===12&&(s.hours=0),delete s.afternoon}}(v),v}}return function(D,S,W){W.p.customParseFormat=!0,D&&D.parseTwoDigitYear&&(A=D.parseTwoDigitYear);var Y=S.prototype,N=Y.parse;Y.parse=function(G){var E=G.date,T=G.utc,d=G.args;this.$u=T;var u=d[1];if(typeof u=="string"){var p=d[2]===!0,v=d[3]===!0,g=p||v,f=d[2];v&&(f=d[2]),O=this.$locale(),!p&&f&&(O=W.Ls[f]),this.$d=function(m,x,s,P){try{if(["x","X"].indexOf(x)>-1)return new Date((x==="X"?1e3:1)*m);var e=M(x)(m),w=e.year,L=e.month,$=e.day,I=e.hours,j=e.minutes,C=e.seconds,Q=e.milliseconds,st=e.zone,ot=e.week,ht=new Date,mt=$||(w||L?1:ht.getDate()),ct=w||ht.getFullYear(),H=0;w&&!L||(H=L>0?L-1:ht.getMonth());var Z,q=I||0,rt=j||0,K=C||0,it=Q||0;return st?new Date(Date.UTC(ct,H,mt,q,rt,K,it+60*st.offset*1e3)):s?new Date(Date.UTC(ct,H,mt,q,rt,K,it)):(Z=new Date(ct,H,mt,q,rt,K,it),ot&&(Z=P(Z).week(ot).toDate()),Z)}catch{return new Date("")}}(E,u,T,W),this.init(),f&&f!==!0&&(this.$L=this.locale(f).$L),g&&E!=this.format(u)&&(this.$d=new Date("")),O={}}else if(u instanceof Array)for(var o=u.length,l=1;l<=o;l+=1){d[1]=u[l-1];var h=W.apply(this,d);if(h.isValid()){this.$d=h.$d,this.$L=h.$L,this.init();break}l===o&&(this.$d=new Date(""))}else N.call(this,G)}}})})(te);var Pe=te.exports;const Ve=Dt(Pe);var ee={exports:{}};(function(t,r){(function(n,i){t.exports=i()})(_t,function(){return function(n,i){var a=i.prototype,k=a.format;a.format=function(y){var _=this,O=this.$locale();if(!this.isValid())return k.bind(this)(y);var A=this.$utils(),b=(y||"YYYY-MM-DDTHH:mm:ssZ").replace(/\[([^\]]+)]|Q|wo|ww|w|WW|W|zzz|z|gggg|GGGG|Do|X|x|k{1,2}|S/g,function(F){switch(F){case"Q":return Math.ceil((_.$M+1)/3);case"Do":return O.ordinal(_.$D);case"gggg":return _.weekYear();case"GGGG":return _.isoWeekYear();case"wo":return O.ordinal(_.week(),"W");case"w":case"ww":return A.s(_.week(),F==="w"?1:2,"0");case"W":case"WW":return A.s(_.isoWeek(),F==="W"?1:2,"0");case"k":case"kk":return A.s(String(_.$H===0?24:_.$H),F==="k"?1:2,"0");case"X":return Math.floor(_.$d.getTime()/1e3);case"x":return _.$d.getTime();case"z":return"["+_.offsetName()+"]";case"zzz":return"["+_.offsetName("long")+"]";default:return F}});return k.bind(this)(b)}}})})(ee);var ze=ee.exports;const Re=Dt(ze);var se={exports:{}};(function(t,r){(function(n,i){t.exports=i()})(_t,function(){var n,i,a=1e3,k=6e4,y=36e5,_=864e5,O=/\[([^\]]+)]|Y{1,4}|M{1,4}|D{1,2}|d{1,4}|H{1,2}|h{1,2}|a|A|m{1,2}|s{1,2}|Z{1,2}|SSS/g,A=31536e6,b=2628e6,F=/^(-|\+)?P(?:([-+]?[0-9,.]*)Y)?(?:([-+]?[0-9,.]*)M)?(?:([-+]?[0-9,.]*)W)?(?:([-+]?[0-9,.]*)D)?(?:T(?:([-+]?[0-9,.]*)H)?(?:([-+]?[0-9,.]*)M)?(?:([-+]?[0-9,.]*)S)?)?$/,V={years:A,months:b,days:_,hours:y,minutes:k,seconds:a,milliseconds:1,weeks:6048e5},z=function(E){return E instanceof N},R=function(E,T,d){return new N(E,d,T.$l)},M=function(E){return i.p(E)+"s"},D=function(E){return E<0},S=function(E){return D(E)?Math.ceil(E):Math.floor(E)},W=function(E){return Math.abs(E)},Y=function(E,T){return E?D(E)?{negative:!0,format:""+W(E)+T}:{negative:!1,format:""+E+T}:{negative:!1,format:""}},N=function(){function E(d,u,p){var v=this;if(this.$d={},this.$l=p,d===void 0&&(this.$ms=0,this.parseFromMilliseconds()),u)return R(d*V[M(u)],this);if(typeof d=="number")return this.$ms=d,this.parseFromMilliseconds(),this;if(typeof d=="object")return Object.keys(d).forEach(function(o){v.$d[M(o)]=d[o]}),this.calMilliseconds(),this;if(typeof d=="string"){var g=d.match(F);if(g){var f=g.slice(2).map(function(o){return o!=null?Number(o):0});return this.$d.years=f[0],this.$d.months=f[1],this.$d.weeks=f[2],this.$d.days=f[3],this.$d.hours=f[4],this.$d.minutes=f[5],this.$d.seconds=f[6],this.calMilliseconds(),this}}return this}var T=E.prototype;return T.calMilliseconds=function(){var d=this;this.$ms=Object.keys(this.$d).reduce(function(u,p){return u+(d.$d[p]||0)*V[p]},0)},T.parseFromMilliseconds=function(){var d=this.$ms;this.$d.years=S(d/A),d%=A,this.$d.months=S(d/b),d%=b,this.$d.days=S(d/_),d%=_,this.$d.hours=S(d/y),d%=y,this.$d.minutes=S(d/k),d%=k,this.$d.seconds=S(d/a),d%=a,this.$d.milliseconds=d},T.toISOString=function(){var d=Y(this.$d.years,"Y"),u=Y(this.$d.months,"M"),p=+this.$d.days||0;this.$d.weeks&&(p+=7*this.$d.weeks);var v=Y(p,"D"),g=Y(this.$d.hours,"H"),f=Y(this.$d.minutes,"M"),o=this.$d.seconds||0;this.$d.milliseconds&&(o+=this.$d.milliseconds/1e3,o=Math.round(1e3*o)/1e3);var l=Y(o,"S"),h=d.negative||u.negative||v.negative||g.negative||f.negative||l.negative,m=g.format||f.format||l.format?"T":"",x=(h?"-":"")+"P"+d.format+u.format+v.format+m+g.format+f.format+l.format;return x==="P"||x==="-P"?"P0D":x},T.toJSON=function(){return this.toISOString()},T.format=function(d){var u=d||"YYYY-MM-DDTHH:mm:ss",p={Y:this.$d.years,YY:i.s(this.$d.years,2,"0"),YYYY:i.s(this.$d.years,4,"0"),M:this.$d.months,MM:i.s(this.$d.months,2,"0"),D:this.$d.days,DD:i.s(this.$d.days,2,"0"),H:this.$d.hours,HH:i.s(this.$d.hours,2,"0"),m:this.$d.minutes,mm:i.s(this.$d.minutes,2,"0"),s:this.$d.seconds,ss:i.s(this.$d.seconds,2,"0"),SSS:i.s(this.$d.milliseconds,3,"0")};return u.replace(O,function(v,g){return g||String(p[v])})},T.as=function(d){return this.$ms/V[M(d)]},T.get=function(d){var u=this.$ms,p=M(d);return p==="milliseconds"?u%=1e3:u=p==="weeks"?S(u/V[p]):this.$d[p],u||0},T.add=function(d,u,p){var v;return v=u?d*V[M(u)]:z(d)?d.$ms:R(d,this).$ms,R(this.$ms+v*(p?-1:1),this)},T.subtract=function(d,u){return this.add(d,u,!0)},T.locale=function(d){var u=this.clone();return u.$l=d,u},T.clone=function(){return R(this.$ms,this)},T.humanize=function(d){return n().add(this.$ms,"ms").locale(this.$l).fromNow(!d)},T.valueOf=function(){return this.asMilliseconds()},T.milliseconds=function(){return this.get("milliseconds")},T.asMilliseconds=function(){return this.as("milliseconds")},T.seconds=function(){return this.get("seconds")},T.asSeconds=function(){return this.as("seconds")},T.minutes=function(){return this.get("minutes")},T.asMinutes=function(){return this.as("minutes")},T.hours=function(){return this.get("hours")},T.asHours=function(){return this.as("hours")},T.days=function(){return this.get("days")},T.asDays=function(){return this.as("days")},T.weeks=function(){return this.get("weeks")},T.asWeeks=function(){return this.as("weeks")},T.months=function(){return this.get("months")},T.asMonths=function(){return this.as("months")},T.years=function(){return this.get("years")},T.asYears=function(){return this.as("years")},E}(),G=function(E,T,d){return E.add(T.years()*d,"y").add(T.months()*d,"M").add(T.days()*d,"d").add(T.hours()*d,"h").add(T.minutes()*d,"m").add(T.seconds()*d,"s").add(T.milliseconds()*d,"ms")};return function(E,T,d){n=d,i=d().$utils(),d.duration=function(v,g){var f=d.locale();return R(v,{$l:f},g)},d.isDuration=z;var u=T.prototype.add,p=T.prototype.subtract;T.prototype.add=function(v,g){return z(v)?G(this,v,1):u.bind(this)(v,g)},T.prototype.subtract=function(v,g){return z(v)?G(this,v,-1):p.bind(this)(v,g)}}})})(se);var Ne=se.exports;const He=Dt(Ne);var Mt=function(){var t=c(function(f,o,l,h){for(l=l||{},h=f.length;h--;l[f[h]]=o);return l},"o"),r=[6,8,10,12,13,14,15,16,17,18,20,21,22,23,24,25,26,27,28,29,30,31,33,35,36,38,40],n=[1,26],i=[1,27],a=[1,28],k=[1,29],y=[1,30],_=[1,31],O=[1,32],A=[1,33],b=[1,34],F=[1,9],V=[1,10],z=[1,11],R=[1,12],M=[1,13],D=[1,14],S=[1,15],W=[1,16],Y=[1,19],N=[1,20],G=[1,21],E=[1,22],T=[1,23],d=[1,25],u=[1,35],p={trace:c(function(){},"trace"),yy:{},symbols_:{error:2,start:3,gantt:4,document:5,EOF:6,line:7,SPACE:8,statement:9,NL:10,weekday:11,weekday_monday:12,weekday_tuesday:13,weekday_wednesday:14,weekday_thursday:15,weekday_friday:16,weekday_saturday:17,weekday_sunday:18,weekend:19,weekend_friday:20,weekend_saturday:21,dateFormat:22,inclusiveEndDates:23,topAxis:24,axisFormat:25,tickInterval:26,excludes:27,includes:28,todayMarker:29,title:30,acc_title:31,acc_title_value:32,acc_descr:33,acc_descr_value:34,acc_descr_multiline_value:35,section:36,clickStatement:37,taskTxt:38,taskData:39,click:40,callbackname:41,callbackargs:42,href:43,clickStatementDebug:44,$accept:0,$end:1},terminals_:{2:"error",4:"gantt",6:"EOF",8:"SPACE",10:"NL",12:"weekday_monday",13:"weekday_tuesday",14:"weekday_wednesday",15:"weekday_thursday",16:"weekday_friday",17:"weekday_saturday",18:"weekday_sunday",20:"weekend_friday",21:"weekend_saturday",22:"dateFormat",23:"inclusiveEndDates",24:"topAxis",25:"axisFormat",26:"tickInterval",27:"excludes",28:"includes",29:"todayMarker",30:"title",31:"acc_title",32:"acc_title_value",33:"acc_descr",34:"acc_descr_value",35:"acc_descr_multiline_value",36:"section",38:"taskTxt",39:"taskData",40:"click",41:"callbackname",42:"callbackargs",43:"href"},productions_:[0,[3,3],[5,0],[5,2],[7,2],[7,1],[7,1],[7,1],[11,1],[11,1],[11,1],[11,1],[11,1],[11,1],[11,1],[19,1],[19,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,2],[9,2],[9,1],[9,1],[9,1],[9,2],[37,2],[37,3],[37,3],[37,4],[37,3],[37,4],[37,2],[44,2],[44,3],[44,3],[44,4],[44,3],[44,4],[44,2]],performAction:c(function(o,l,h,m,x,s,P){var e=s.length-1;switch(x){case 1:return s[e-1];case 2:this.$=[];break;case 3:s[e-1].push(s[e]),this.$=s[e-1];break;case 4:case 5:this.$=s[e];break;case 6:case 7:this.$=[];break;case 8:m.setWeekday("monday");break;case 9:m.setWeekday("tuesday");break;case 10:m.setWeekday("wednesday");break;case 11:m.setWeekday("thursday");break;case 12:m.setWeekday("friday");break;case 13:m.setWeekday("saturday");break;case 14:m.setWeekday("sunday");break;case 15:m.setWeekend("friday");break;case 16:m.setWeekend("saturday");break;case 17:m.setDateFormat(s[e].substr(11)),this.$=s[e].substr(11);break;case 18:m.enableInclusiveEndDates(),this.$=s[e].substr(18);break;case 19:m.TopAxis(),this.$=s[e].substr(8);break;case 20:m.setAxisFormat(s[e].substr(11)),this.$=s[e].substr(11);break;case 21:m.setTickInterval(s[e].substr(13)),this.$=s[e].substr(13);break;case 22:m.setExcludes(s[e].substr(9)),this.$=s[e].substr(9);break;case 23:m.setIncludes(s[e].substr(9)),this.$=s[e].substr(9);break;case 24:m.setTodayMarker(s[e].substr(12)),this.$=s[e].substr(12);break;case 27:m.setDiagramTitle(s[e].substr(6)),this.$=s[e].substr(6);break;case 28:this.$=s[e].trim(),m.setAccTitle(this.$);break;case 29:case 30:this.$=s[e].trim(),m.setAccDescription(this.$);break;case 31:m.addSection(s[e].substr(8)),this.$=s[e].substr(8);break;case 33:m.addTask(s[e-1],s[e]),this.$="task";break;case 34:this.$=s[e-1],m.setClickEvent(s[e-1],s[e],null);break;case 35:this.$=s[e-2],m.setClickEvent(s[e-2],s[e-1],s[e]);break;case 36:this.$=s[e-2],m.setClickEvent(s[e-2],s[e-1],null),m.setLink(s[e-2],s[e]);break;case 37:this.$=s[e-3],m.setClickEvent(s[e-3],s[e-2],s[e-1]),m.setLink(s[e-3],s[e]);break;case 38:this.$=s[e-2],m.setClickEvent(s[e-2],s[e],null),m.setLink(s[e-2],s[e-1]);break;case 39:this.$=s[e-3],m.setClickEvent(s[e-3],s[e-1],s[e]),m.setLink(s[e-3],s[e-2]);break;case 40:this.$=s[e-1],m.setLink(s[e-1],s[e]);break;case 41:case 47:this.$=s[e-1]+" "+s[e];break;case 42:case 43:case 45:this.$=s[e-2]+" "+s[e-1]+" "+s[e];break;case 44:case 46:this.$=s[e-3]+" "+s[e-2]+" "+s[e-1]+" "+s[e];break}},"anonymous"),table:[{3:1,4:[1,2]},{1:[3]},t(r,[2,2],{5:3}),{6:[1,4],7:5,8:[1,6],9:7,10:[1,8],11:17,12:n,13:i,14:a,15:k,16:y,17:_,18:O,19:18,20:A,21:b,22:F,23:V,24:z,25:R,26:M,27:D,28:S,29:W,30:Y,31:N,33:G,35:E,36:T,37:24,38:d,40:u},t(r,[2,7],{1:[2,1]}),t(r,[2,3]),{9:36,11:17,12:n,13:i,14:a,15:k,16:y,17:_,18:O,19:18,20:A,21:b,22:F,23:V,24:z,25:R,26:M,27:D,28:S,29:W,30:Y,31:N,33:G,35:E,36:T,37:24,38:d,40:u},t(r,[2,5]),t(r,[2,6]),t(r,[2,17]),t(r,[2,18]),t(r,[2,19]),t(r,[2,20]),t(r,[2,21]),t(r,[2,22]),t(r,[2,23]),t(r,[2,24]),t(r,[2,25]),t(r,[2,26]),t(r,[2,27]),{32:[1,37]},{34:[1,38]},t(r,[2,30]),t(r,[2,31]),t(r,[2,32]),{39:[1,39]},t(r,[2,8]),t(r,[2,9]),t(r,[2,10]),t(r,[2,11]),t(r,[2,12]),t(r,[2,13]),t(r,[2,14]),t(r,[2,15]),t(r,[2,16]),{41:[1,40],43:[1,41]},t(r,[2,4]),t(r,[2,28]),t(r,[2,29]),t(r,[2,33]),t(r,[2,34],{42:[1,42],43:[1,43]}),t(r,[2,40],{41:[1,44]}),t(r,[2,35],{43:[1,45]}),t(r,[2,36]),t(r,[2,38],{42:[1,46]}),t(r,[2,37]),t(r,[2,39])],defaultActions:{},parseError:c(function(o,l){if(l.recoverable)this.trace(o);else{var h=new Error(o);throw h.hash=l,h}},"parseError"),parse:c(function(o){var l=this,h=[0],m=[],x=[null],s=[],P=this.table,e="",w=0,L=0,$=2,I=1,j=s.slice.call(arguments,1),C=Object.create(this.lexer),Q={yy:{}};for(var st in this.yy)Object.prototype.hasOwnProperty.call(this.yy,st)&&(Q.yy[st]=this.yy[st]);C.setInput(o,Q.yy),Q.yy.lexer=C,Q.yy.parser=this,typeof C.yylloc>"u"&&(C.yylloc={});var ot=C.yylloc;s.push(ot);var ht=C.options&&C.options.ranges;typeof Q.yy.parseError=="function"?this.parseError=Q.yy.parseError:this.parseError=Object.getPrototypeOf(this).parseError;function mt(U){h.length=h.length-2*U,x.length=x.length-U,s.length=s.length-U}c(mt,"popStack");function ct(){var U;return U=m.pop()||C.lex()||I,typeof U!="number"&&(U instanceof Array&&(m=U,U=m.pop()),U=l.symbols_[U]||U),U}c(ct,"lex");for(var H,Z,q,rt,K={},it,J,Nt,vt;;){if(Z=h[h.length-1],this.defaultActions[Z]?q=this.defaultActions[Z]:((H===null||typeof H>"u")&&(H=ct()),q=P[Z]&&P[Z][H]),typeof q>"u"||!q.length||!q[0]){var St="";vt=[];for(it in P[Z])this.terminals_[it]&&it>$&&vt.push("'"+this.terminals_[it]+"'");C.showPosition?St="Parse error on line "+(w+1)+`:
`+C.showPosition()+`
Expecting `+vt.join(", ")+", got '"+(this.terminals_[H]||H)+"'":St="Parse error on line "+(w+1)+": Unexpected "+(H==I?"end of input":"'"+(this.terminals_[H]||H)+"'"),this.parseError(St,{text:C.match,token:this.terminals_[H]||H,line:C.yylineno,loc:ot,expected:vt})}if(q[0]instanceof Array&&q.length>1)throw new Error("Parse Error: multiple actions possible at state: "+Z+", token: "+H);switch(q[0]){case 1:h.push(H),x.push(C.yytext),s.push(C.yylloc),h.push(q[1]),H=null,L=C.yyleng,e=C.yytext,w=C.yylineno,ot=C.yylloc;break;case 2:if(J=this.productions_[q[1]][1],K.$=x[x.length-J],K._$={first_line:s[s.length-(J||1)].first_line,last_line:s[s.length-1].last_line,first_column:s[s.length-(J||1)].first_column,last_column:s[s.length-1].last_column},ht&&(K._$.range=[s[s.length-(J||1)].range[0],s[s.length-1].range[1]]),rt=this.performAction.apply(K,[e,L,w,Q.yy,q[1],x,s].concat(j)),typeof rt<"u")return rt;J&&(h=h.slice(0,-1*J*2),x=x.slice(0,-1*J),s=s.slice(0,-1*J)),h.push(this.productions_[q[1]][0]),x.push(K.$),s.push(K._$),Nt=P[h[h.length-2]][h[h.length-1]],h.push(Nt);break;case 3:return!0}}return!0},"parse")},v=function(){var f={EOF:1,parseError:c(function(l,h){if(this.yy.parser)this.yy.parser.parseError(l,h);else throw new Error(l)},"parseError"),setInput:c(function(o,l){return this.yy=l||this.yy||{},this._input=o,this._more=this._backtrack=this.done=!1,this.yylineno=this.yyleng=0,this.yytext=this.matched=this.match="",this.conditionStack=["INITIAL"],this.yylloc={first_line:1,first_column:0,last_line:1,last_column:0},this.options.ranges&&(this.yylloc.range=[0,0]),this.offset=0,this},"setInput"),input:c(function(){var o=this._input[0];this.yytext+=o,this.yyleng++,this.offset++,this.match+=o,this.matched+=o;var l=o.match(/(?:\r\n?|\n).*/g);return l?(this.yylineno++,this.yylloc.last_line++):this.yylloc.last_column++,this.options.ranges&&this.yylloc.range[1]++,this._input=this._input.slice(1),o},"input"),unput:c(function(o){var l=o.length,h=o.split(/(?:\r\n?|\n)/g);this._input=o+this._input,this.yytext=this.yytext.substr(0,this.yytext.length-l),this.offset-=l;var m=this.match.split(/(?:\r\n?|\n)/g);this.match=this.match.substr(0,this.match.length-1),this.matched=this.matched.substr(0,this.matched.length-1),h.length-1&&(this.yylineno-=h.length-1);var x=this.yylloc.range;return this.yylloc={first_line:this.yylloc.first_line,last_line:this.yylineno+1,first_column:this.yylloc.first_column,last_column:h?(h.length===m.length?this.yylloc.first_column:0)+m[m.length-h.length].length-h[0].length:this.yylloc.first_column-l},this.options.ranges&&(this.yylloc.range=[x[0],x[0]+this.yyleng-l]),this.yyleng=this.yytext.length,this},"unput"),more:c(function(){return this._more=!0,this},"more"),reject:c(function(){if(this.options.backtrack_lexer)this._backtrack=!0;else return this.parseError("Lexical error on line "+(this.yylineno+1)+`. You can only invoke reject() in the lexer when the lexer is of the backtracking persuasion (options.backtrack_lexer = true).
`+this.showPosition(),{text:"",token:null,line:this.yylineno});return this},"reject"),less:c(function(o){this.unput(this.match.slice(o))},"less"),pastInput:c(function(){var o=this.matched.substr(0,this.matched.length-this.match.length);return(o.length>20?"...":"")+o.substr(-20).replace(/\n/g,"")},"pastInput"),upcomingInput:c(function(){var o=this.match;return o.length<20&&(o+=this._input.substr(0,20-o.length)),(o.substr(0,20)+(o.length>20?"...":"")).replace(/\n/g,"")},"upcomingInput"),showPosition:c(function(){var o=this.pastInput(),l=new Array(o.length+1).join("-");return o+this.upcomingInput()+`
`+l+"^"},"showPosition"),test_match:c(function(o,l){var h,m,x;if(this.options.backtrack_lexer&&(x={yylineno:this.yylineno,yylloc:{first_line:this.yylloc.first_line,last_line:this.last_line,first_column:this.yylloc.first_column,last_column:this.yylloc.last_column},yytext:this.yytext,match:this.match,matches:this.matches,matched:this.matched,yyleng:this.yyleng,offset:this.offset,_more:this._more,_input:this._input,yy:this.yy,conditionStack:this.conditionStack.slice(0),done:this.done},this.options.ranges&&(x.yylloc.range=this.yylloc.range.slice(0))),m=o[0].match(/(?:\r\n?|\n).*/g),m&&(this.yylineno+=m.length),this.yylloc={first_line:this.yylloc.last_line,last_line:this.yylineno+1,first_column:this.yylloc.last_column,last_column:m?m[m.length-1].length-m[m.length-1].match(/\r?\n?/)[0].length:this.yylloc.last_column+o[0].length},this.yytext+=o[0],this.match+=o[0],this.matches=o,this.yyleng=this.yytext.length,this.options.ranges&&(this.yylloc.range=[this.offset,this.offset+=this.yyleng]),this._more=!1,this._backtrack=!1,this._input=this._input.slice(o[0].length),this.matched+=o[0],h=this.performAction.call(this,this.yy,this,l,this.conditionStack[this.conditionStack.length-1]),this.done&&this._input&&(this.done=!1),h)return h;if(this._backtrack){for(var s in x)this[s]=x[s];return!1}return!1},"test_match"),next:c(function(){if(this.done)return this.EOF;this._input||(this.done=!0);var o,l,h,m;this._more||(this.yytext="",this.match="");for(var x=this._currentRules(),s=0;s<x.length;s++)if(h=this._input.match(this.rules[x[s]]),h&&(!l||h[0].length>l[0].length)){if(l=h,m=s,this.options.backtrack_lexer){if(o=this.test_match(h,x[s]),o!==!1)return o;if(this._backtrack){l=!1;continue}else return!1}else if(!this.options.flex)break}return l?(o=this.test_match(l,x[m]),o!==!1?o:!1):this._input===""?this.EOF:this.parseError("Lexical error on line "+(this.yylineno+1)+`. Unrecognized text.
`+this.showPosition(),{text:"",token:null,line:this.yylineno})},"next"),lex:c(function(){var l=this.next();return l||this.lex()},"lex"),begin:c(function(l){this.conditionStack.push(l)},"begin"),popState:c(function(){var l=this.conditionStack.length-1;return l>0?this.conditionStack.pop():this.conditionStack[0]},"popState"),_currentRules:c(function(){return this.conditionStack.length&&this.conditionStack[this.conditionStack.length-1]?this.conditions[this.conditionStack[this.conditionStack.length-1]].rules:this.conditions.INITIAL.rules},"_currentRules"),topState:c(function(l){return l=this.conditionStack.length-1-Math.abs(l||0),l>=0?this.conditionStack[l]:"INITIAL"},"topState"),pushState:c(function(l){this.begin(l)},"pushState"),stateStackSize:c(function(){return this.conditionStack.length},"stateStackSize"),options:{"case-insensitive":!0},performAction:c(function(l,h,m,x){switch(m){case 0:return this.begin("open_directive"),"open_directive";case 1:return this.begin("acc_title"),31;case 2:return this.popState(),"acc_title_value";case 3:return this.begin("acc_descr"),33;case 4:return this.popState(),"acc_descr_value";case 5:this.begin("acc_descr_multiline");break;case 6:this.popState();break;case 7:return"acc_descr_multiline_value";case 8:break;case 9:break;case 10:break;case 11:return 10;case 12:break;case 13:break;case 14:this.begin("href");break;case 15:this.popState();break;case 16:return 43;case 17:this.begin("callbackname");break;case 18:this.popState();break;case 19:this.popState(),this.begin("callbackargs");break;case 20:return 41;case 21:this.popState();break;case 22:return 42;case 23:this.begin("click");break;case 24:this.popState();break;case 25:return 40;case 26:return 4;case 27:return 22;case 28:return 23;case 29:return 24;case 30:return 25;case 31:return 26;case 32:return 28;case 33:return 27;case 34:return 29;case 35:return 12;case 36:return 13;case 37:return 14;case 38:return 15;case 39:return 16;case 40:return 17;case 41:return 18;case 42:return 20;case 43:return 21;case 44:return"date";case 45:return 30;case 46:return"accDescription";case 47:return 36;case 48:return 38;case 49:return 39;case 50:return":";case 51:return 6;case 52:return"INVALID"}},"anonymous"),rules:[/^(?:%%\{)/i,/^(?:accTitle\s*:\s*)/i,/^(?:(?!\n||)*[^\n]*)/i,/^(?:accDescr\s*:\s*)/i,/^(?:(?!\n||)*[^\n]*)/i,/^(?:accDescr\s*\{\s*)/i,/^(?:[\}])/i,/^(?:[^\}]*)/i,/^(?:%%(?!\{)*[^\n]*)/i,/^(?:[^\}]%%*[^\n]*)/i,/^(?:%%*[^\n]*[\n]*)/i,/^(?:[\n]+)/i,/^(?:\s+)/i,/^(?:%[^\n]*)/i,/^(?:href[\s]+["])/i,/^(?:["])/i,/^(?:[^"]*)/i,/^(?:call[\s]+)/i,/^(?:\([\s]*\))/i,/^(?:\()/i,/^(?:[^(]*)/i,/^(?:\))/i,/^(?:[^)]*)/i,/^(?:click[\s]+)/i,/^(?:[\s\n])/i,/^(?:[^\s\n]*)/i,/^(?:gantt\b)/i,/^(?:dateFormat\s[^#\n;]+)/i,/^(?:inclusiveEndDates\b)/i,/^(?:topAxis\b)/i,/^(?:axisFormat\s[^#\n;]+)/i,/^(?:tickInterval\s[^#\n;]+)/i,/^(?:includes\s[^#\n;]+)/i,/^(?:excludes\s[^#\n;]+)/i,/^(?:todayMarker\s[^\n;]+)/i,/^(?:weekday\s+monday\b)/i,/^(?:weekday\s+tuesday\b)/i,/^(?:weekday\s+wednesday\b)/i,/^(?:weekday\s+thursday\b)/i,/^(?:weekday\s+friday\b)/i,/^(?:weekday\s+saturday\b)/i,/^(?:weekday\s+sunday\b)/i,/^(?:weekend\s+friday\b)/i,/^(?:weekend\s+saturday\b)/i,/^(?:\d\d\d\d-\d\d-\d\d\b)/i,/^(?:title\s[^\n]+)/i,/^(?:accDescription\s[^#\n;]+)/i,/^(?:section\s[^\n]+)/i,/^(?:[^:\n]+)/i,/^(?::[^#\n;]+)/i,/^(?::)/i,/^(?:$)/i,/^(?:.)/i],conditions:{acc_descr_multiline:{rules:[6,7],inclusive:!1},acc_descr:{rules:[4],inclusive:!1},acc_title:{rules:[2],inclusive:!1},callbackargs:{rules:[21,22],inclusive:!1},callbackname:{rules:[18,19,20],inclusive:!1},href:{rules:[15,16],inclusive:!1},click:{rules:[24,25],inclusive:!1},INITIAL:{rules:[0,1,3,5,8,9,10,11,12,13,14,17,23,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52],inclusive:!0}}};return f}();p.lexer=v;function g(){this.yy={}}return c(g,"Parser"),g.prototype=p,p.Parser=g,new g}();Mt.parser=Mt;var Be=Mt;X.extend(We);X.extend(Ve);X.extend(Re);var Zt={friday:5,saturday:6},tt="",Yt="",Lt=void 0,At="",kt=[],yt=[],Ft=new Map,Ot=[],bt=[],ft="",Wt="",ie=["active","done","crit","milestone","vert"],Pt=[],lt="",gt=!1,Vt=!1,zt="sunday",wt="saturday",Et=0,Ge=c(function(){Ot=[],bt=[],ft="",Pt=[],Tt=0,$t=void 0,xt=void 0,B=[],tt="",Yt="",Wt="",Lt=void 0,At="",kt=[],yt=[],gt=!1,Vt=!1,Et=0,Ft=new Map,lt="",be(),zt="sunday",wt="saturday"},"clear"),je=c(function(t){lt=t},"setDiagramId"),Xe=c(function(t){Yt=t},"setAxisFormat"),qe=c(function(){return Yt},"getAxisFormat"),Ue=c(function(t){Lt=t},"setTickInterval"),Ze=c(function(){return Lt},"getTickInterval"),Qe=c(function(t){At=t},"setTodayMarker"),Ke=c(function(){return At},"getTodayMarker"),Je=c(function(t){tt=t},"setDateFormat"),ts=c(function(){gt=!0},"enableInclusiveEndDates"),es=c(function(){return gt},"endDatesAreInclusive"),ss=c(function(){Vt=!0},"enableTopAxis"),is=c(function(){return Vt},"topAxisEnabled"),rs=c(function(t){Wt=t},"setDisplayMode"),ns=c(function(){return Wt},"getDisplayMode"),as=c(function(){return tt},"getDateFormat"),os=c(function(t){kt=t.toLowerCase().split(/[\s,]+/)},"setIncludes"),cs=c(function(){return kt},"getIncludes"),ls=c(function(t){yt=t.toLowerCase().split(/[\s,]+/)},"setExcludes"),us=c(function(){return yt},"getExcludes"),ds=c(function(){return Ft},"getLinks"),fs=c(function(t){ft=t,Ot.push(t)},"addSection"),hs=c(function(){return Ot},"getSections"),ms=c(function(){let t=Qt();const r=10;let n=0;for(;!t&&n<r;)t=Qt(),n++;return bt=B,bt},"getTasks"),re=c(function(t,r,n,i){const a=t.format(r.trim()),k=t.format("YYYY-MM-DD");return i.includes(a)||i.includes(k)?!1:n.includes("weekends")&&(t.isoWeekday()===Zt[wt]||t.isoWeekday()===Zt[wt]+1)||n.includes(t.format("dddd").toLowerCase())?!0:n.includes(a)||n.includes(k)},"isInvalidDate"),ks=c(function(t){zt=t},"setWeekday"),ys=c(function(){return zt},"getWeekday"),gs=c(function(t){wt=t},"setWeekend"),ne=c(function(t,r,n,i){if(!n.length||t.manualEndTime)return;let a;t.startTime instanceof Date?a=X(t.startTime):a=X(t.startTime,r,!0),a=a.add(1,"d");let k;t.endTime instanceof Date?k=X(t.endTime):k=X(t.endTime,r,!0);const[y,_]=vs(a,k,r,n,i);t.endTime=y.toDate(),t.renderEndTime=_},"checkTaskDates"),vs=c(function(t,r,n,i,a){let k=!1,y=null;for(;t<=r;)k||(y=r.toDate()),k=re(t,n,i,a),k&&(r=r.add(1,"d")),t=t.add(1,"d");return[r,y]},"fixTaskDates"),It=c(function(t,r,n){if(n=n.trim(),c(_=>{const O=_.trim();return O==="x"||O==="X"},"isTimestampFormat")(r)&&/^\d+$/.test(n))return new Date(Number(n));const k=/^after\s+(?<ids>[\d\w- ]+)/.exec(n);if(k!==null){let _=null;for(const A of k.groups.ids.split(" ")){let b=at(A);b!==void 0&&(!_||b.endTime>_.endTime)&&(_=b)}if(_)return _.endTime;const O=new Date;return O.setHours(0,0,0,0),O}let y=X(n,r.trim(),!0);if(y.isValid())return y.toDate();{nt.debug("Invalid date:"+n),nt.debug("With date format:"+r.trim());const _=new Date(n);if(_===void 0||isNaN(_.getTime())||_.getFullYear()<-1e4||_.getFullYear()>1e4)throw new Error("Invalid date:"+n);return _}},"getStartDate"),ae=c(function(t){const r=/^(\d+(?:\.\d+)?)([Mdhmswy]|ms)$/.exec(t.trim());return r!==null?[Number.parseFloat(r[1]),r[2]]:[NaN,"ms"]},"parseDuration"),oe=c(function(t,r,n,i=!1){n=n.trim();const k=/^until\s+(?<ids>[\d\w- ]+)/.exec(n);if(k!==null){let b=null;for(const V of k.groups.ids.split(" ")){let z=at(V);z!==void 0&&(!b||z.startTime<b.startTime)&&(b=z)}if(b)return b.startTime;const F=new Date;return F.setHours(0,0,0,0),F}let y=X(n,r.trim(),!0);if(y.isValid())return i&&(y=y.add(1,"d")),y.toDate();let _=X(t);const[O,A]=ae(n);if(!Number.isNaN(O)){const b=_.add(O,A);b.isValid()&&(_=b)}return _.toDate()},"getEndDate"),Tt=0,dt=c(function(t){return t===void 0?(Tt=Tt+1,"task"+Tt):t},"parseId"),ps=c(function(t,r){let n;r.substr(0,1)===":"?n=r.substr(1,r.length):n=r;const i=n.split(","),a={};Rt(i,a,ie);for(let y=0;y<i.length;y++)i[y]=i[y].trim();let k="";switch(i.length){case 1:a.id=dt(),a.startTime=t.endTime,k=i[0];break;case 2:a.id=dt(),a.startTime=It(void 0,tt,i[0]),k=i[1];break;case 3:a.id=dt(i[0]),a.startTime=It(void 0,tt,i[1]),k=i[2];break}return k&&(a.endTime=oe(a.startTime,tt,k,gt),a.manualEndTime=X(k,"YYYY-MM-DD",!0).isValid(),ne(a,tt,yt,kt)),a},"compileData"),Ts=c(function(t,r){let n;r.substr(0,1)===":"?n=r.substr(1,r.length):n=r;const i=n.split(","),a={};Rt(i,a,ie);for(let k=0;k<i.length;k++)i[k]=i[k].trim();switch(i.length){case 1:a.id=dt(),a.startTime={type:"prevTaskEnd",id:t},a.endTime={data:i[0]};break;case 2:a.id=dt(),a.startTime={type:"getStartDate",startData:i[0]},a.endTime={data:i[1]};break;case 3:a.id=dt(i[0]),a.startTime={type:"getStartDate",startData:i[1]},a.endTime={data:i[2]};break}return a},"parseData"),$t,xt,B=[],ce={},xs=c(function(t,r){const n={section:ft,type:ft,processed:!1,manualEndTime:!1,renderEndTime:null,raw:{data:r},task:t,classes:[]},i=Ts(xt,r);n.raw.startTime=i.startTime,n.raw.endTime=i.endTime,n.id=i.id,n.prevTaskId=xt,n.active=i.active,n.done=i.done,n.crit=i.crit,n.milestone=i.milestone,n.vert=i.vert,n.order=Et,Et++;const a=B.push(n);xt=n.id,ce[n.id]=a-1},"addTask"),at=c(function(t){const r=ce[t];return B[r]},"findTaskById"),bs=c(function(t,r){const n={section:ft,type:ft,description:t,task:t,classes:[]},i=ps($t,r);n.startTime=i.startTime,n.endTime=i.endTime,n.id=i.id,n.active=i.active,n.done=i.done,n.crit=i.crit,n.milestone=i.milestone,n.vert=i.vert,$t=n,bt.push(n)},"addTaskOrg"),Qt=c(function(){const t=c(function(n){const i=B[n];let a="";switch(B[n].raw.startTime.type){case"prevTaskEnd":{const k=at(i.prevTaskId);i.startTime=k.endTime;break}case"getStartDate":a=It(void 0,tt,B[n].raw.startTime.startData),a&&(B[n].startTime=a);break}return B[n].startTime&&(B[n].endTime=oe(B[n].startTime,tt,B[n].raw.endTime.data,gt),B[n].endTime&&(B[n].processed=!0,B[n].manualEndTime=X(B[n].raw.endTime.data,"YYYY-MM-DD",!0).isValid(),ne(B[n],tt,yt,kt))),B[n].processed},"compileTask");let r=!0;for(const[n,i]of B.entries())t(n),r=r&&i.processed;return r},"compileTasks"),ws=c(function(t,r){let n=r;ut().securityLevel!=="loose"&&(n=xe(r)),t.split(",").forEach(function(i){at(i)!==void 0&&(ue(i,()=>{window.open(n,"_self")}),Ft.set(i,n))}),le(t,"clickable")},"setLink"),le=c(function(t,r){t.split(",").forEach(function(n){let i=at(n);i!==void 0&&i.classes.push(r)})},"setClass"),_s=c(function(t,r,n){if(ut().securityLevel!=="loose"||r===void 0)return;let i=[];if(typeof n=="string"){i=n.split(/,(?=(?:(?:[^"]*"){2})*[^"]*$)/);for(let k=0;k<i.length;k++){let y=i[k].trim();y.startsWith('"')&&y.endsWith('"')&&(y=y.substr(1,y.length-2)),i[k]=y}}i.length===0&&i.push(t),at(t)!==void 0&&ue(t,()=>{we.runFunc(r,...i)})},"setClickFun"),ue=c(function(t,r){Pt.push(function(){const n=lt?`${lt}-${t}`:t,i=document.querySelector(`[id="${n}"]`);i!==null&&i.addEventListener("click",function(){r()})},function(){const n=lt?`${lt}-${t}`:t,i=document.querySelector(`[id="${n}-text"]`);i!==null&&i.addEventListener("click",function(){r()})})},"pushFun"),Ds=c(function(t,r,n){t.split(",").forEach(function(i){_s(i,r,n)}),le(t,"clickable")},"setClickEvent"),Ss=c(function(t){Pt.forEach(function(r){r(t)})},"bindFunctions"),Cs={getConfig:c(()=>ut().gantt,"getConfig"),clear:Ge,setDateFormat:Je,getDateFormat:as,enableInclusiveEndDates:ts,endDatesAreInclusive:es,enableTopAxis:ss,topAxisEnabled:is,setAxisFormat:Xe,getAxisFormat:qe,setTickInterval:Ue,getTickInterval:Ze,setTodayMarker:Qe,getTodayMarker:Ke,setAccTitle:ye,getAccTitle:ke,setDiagramTitle:me,getDiagramTitle:he,setDiagramId:je,setDisplayMode:rs,getDisplayMode:ns,setAccDescription:fe,getAccDescription:de,addSection:fs,getSections:hs,getTasks:ms,addTask:xs,findTaskById:at,addTaskOrg:bs,setIncludes:os,getIncludes:cs,setExcludes:ls,getExcludes:us,setClickEvent:Ds,setLink:ws,getLinks:ds,bindFunctions:Ss,parseDuration:ae,isInvalidDate:re,setWeekday:ks,getWeekday:ys,setWeekend:gs};function Rt(t,r,n){let i=!0;for(;i;)i=!1,n.forEach(function(a){const k="^\\s*"+a+"\\s*$",y=new RegExp(k);t[0].match(y)&&(r[a]=!0,t.shift(1),i=!0)})}c(Rt,"getTaskTags");X.extend(He);var Ms=c(function(){nt.debug("Something is calling, setConf, remove the call")},"setConf"),Kt={monday:Fe,tuesday:Ae,wednesday:Le,thursday:Ye,friday:$e,saturday:Ie,sunday:Ee},Es=c((t,r)=>{let n=[...t].map(()=>-1/0),i=[...t].sort((k,y)=>k.startTime-y.startTime||k.order-y.order),a=0;for(const k of i)for(let y=0;y<n.length;y++)if(k.startTime>=n[y]){n[y]=k.endTime,k.order=y+r,y>a&&(a=y);break}return a},"getMaxIntersections"),et,Ct=1e4,Is=c(function(t,r,n,i){const a=ut().gantt;i.db.setDiagramId(r);const k=ut().securityLevel;let y;k==="sandbox"&&(y=pt("#i"+r));const _=k==="sandbox"?pt(y.nodes()[0].contentDocument.body):pt("body"),O=k==="sandbox"?y.nodes()[0].contentDocument:document,A=O.getElementById(r);et=A.parentElement.offsetWidth,et===void 0&&(et=1200),a.useWidth!==void 0&&(et=a.useWidth);const b=i.db.getTasks();let F=[];for(const u of b)F.push(u.type);F=d(F);const V={};let z=2*a.topPadding;if(i.db.getDisplayMode()==="compact"||a.displayMode==="compact"){const u={};for(const v of b)u[v.section]===void 0?u[v.section]=[v]:u[v.section].push(v);let p=0;for(const v of Object.keys(u)){const g=Es(u[v],p)+1;p+=g,z+=g*(a.barHeight+a.barGap),V[v]=g}}else{z+=b.length*(a.barHeight+a.barGap);for(const u of F)V[u]=b.filter(p=>p.type===u).length}A.setAttribute("viewBox","0 0 "+et+" "+z);const R=_.select(`[id="${r}"]`),M=_e().domain([De(b,function(u){return u.startTime}),Se(b,function(u){return u.endTime})]).rangeRound([0,et-a.leftPadding-a.rightPadding]);function D(u,p){const v=u.startTime,g=p.startTime;let f=0;return v>g?f=1:v<g&&(f=-1),f}c(D,"taskCompare"),b.sort(D),S(b,et,z),ge(R,z,et,a.useMaxWidth),R.append("text").text(i.db.getDiagramTitle()).attr("x",et/2).attr("y",a.titleTopMargin).attr("class","titleText");function S(u,p,v){const g=a.barHeight,f=g+a.barGap,o=a.topPadding,l=a.leftPadding,h=Ce().domain([0,F.length]).range(["#00B9FA","#F95002"]).interpolate(Me);Y(f,o,l,p,v,u,i.db.getExcludes(),i.db.getIncludes()),G(l,o,p,v),W(u,f,o,l,g,h,p),E(f,o),T(l,o,p,v)}c(S,"makeGantt");function W(u,p,v,g,f,o,l){u.sort((e,w)=>e.vert===w.vert?0:e.vert?1:-1);const m=[...new Set(u.map(e=>e.order))].map(e=>u.find(w=>w.order===e));R.append("g").selectAll("rect").data(m).enter().append("rect").attr("x",0).attr("y",function(e,w){return w=e.order,w*p+v-2}).attr("width",function(){return l-a.rightPadding/2}).attr("height",p).attr("class",function(e){for(const[w,L]of F.entries())if(e.type===L)return"section section"+w%a.numberSectionStyles;return"section section0"}).enter();const x=R.append("g").selectAll("rect").data(u).enter(),s=i.db.getLinks();if(x.append("rect").attr("id",function(e){return r+"-"+e.id}).attr("rx",3).attr("ry",3).attr("x",function(e){return e.milestone?M(e.startTime)+g+.5*(M(e.endTime)-M(e.startTime))-.5*f:M(e.startTime)+g}).attr("y",function(e,w){return w=e.order,e.vert?a.gridLineStartPadding:w*p+v}).attr("width",function(e){return e.milestone?f:e.vert?.08*f:M(e.renderEndTime||e.endTime)-M(e.startTime)}).attr("height",function(e){return e.vert?b.length*(a.barHeight+a.barGap)+a.barHeight*2:f}).attr("transform-origin",function(e,w){return w=e.order,(M(e.startTime)+g+.5*(M(e.endTime)-M(e.startTime))).toString()+"px "+(w*p+v+.5*f).toString()+"px"}).attr("class",function(e){const w="task";let L="";e.classes.length>0&&(L=e.classes.join(" "));let $=0;for(const[j,C]of F.entries())e.type===C&&($=j%a.numberSectionStyles);let I="";return e.active?e.crit?I+=" activeCrit":I=" active":e.done?e.crit?I=" doneCrit":I=" done":e.crit&&(I+=" crit"),I.length===0&&(I=" task"),e.milestone&&(I=" milestone "+I),e.vert&&(I=" vert "+I),I+=$,I+=" "+L,w+I}),x.append("text").attr("id",function(e){return r+"-"+e.id+"-text"}).text(function(e){return e.task}).attr("font-size",a.fontSize).attr("x",function(e){let w=M(e.startTime),L=M(e.renderEndTime||e.endTime);if(e.milestone&&(w+=.5*(M(e.endTime)-M(e.startTime))-.5*f,L=w+f),e.vert)return M(e.startTime)+g;const $=this.getBBox().width;return $>L-w?L+$+1.5*a.leftPadding>l?w+g-5:L+g+5:(L-w)/2+w+g}).attr("y",function(e,w){return e.vert?a.gridLineStartPadding+b.length*(a.barHeight+a.barGap)+60:(w=e.order,w*p+a.barHeight/2+(a.fontSize/2-2)+v)}).attr("text-height",f).attr("class",function(e){const w=M(e.startTime);let L=M(e.endTime);e.milestone&&(L=w+f);const $=this.getBBox().width;let I="";e.classes.length>0&&(I=e.classes.join(" "));let j=0;for(const[Q,st]of F.entries())e.type===st&&(j=Q%a.numberSectionStyles);let C="";return e.active&&(e.crit?C="activeCritText"+j:C="activeText"+j),e.done?e.crit?C=C+" doneCritText"+j:C=C+" doneText"+j:e.crit&&(C=C+" critText"+j),e.milestone&&(C+=" milestoneText"),e.vert&&(C+=" vertText"),$>L-w?L+$+1.5*a.leftPadding>l?I+" taskTextOutsideLeft taskTextOutside"+j+" "+C:I+" taskTextOutsideRight taskTextOutside"+j+" "+C+" width-"+$:I+" taskText taskText"+j+" "+C+" width-"+$}),ut().securityLevel==="sandbox"){let e;e=pt("#i"+r);const w=e.nodes()[0].contentDocument;x.filter(function(L){return s.has(L.id)}).each(function(L){var $=w.querySelector("#"+CSS.escape(r+"-"+L.id)),I=w.querySelector("#"+CSS.escape(r+"-"+L.id+"-text"));const j=$.parentNode;var C=w.createElement("a");C.setAttribute("xlink:href",s.get(L.id)),C.setAttribute("target","_top"),j.appendChild(C),C.appendChild($),C.appendChild(I)})}}c(W,"drawRects");function Y(u,p,v,g,f,o,l,h){if(l.length===0&&h.length===0)return;let m,x;for(const{startTime:$,endTime:I}of o)(m===void 0||$<m)&&(m=$),(x===void 0||I>x)&&(x=I);if(!m||!x)return;if(X(x).diff(X(m),"year")>5){nt.warn("The difference between the min and max time is more than 5 years. This will cause performance issues. Skipping drawing exclude days.");return}const s=i.db.getDateFormat(),P=[];let e=null,w=X(m);for(;w.valueOf()<=x;)i.db.isInvalidDate(w,s,l,h)?e?e.end=w:e={start:w,end:w}:e&&(P.push(e),e=null),w=w.add(1,"d");R.append("g").selectAll("rect").data(P).enter().append("rect").attr("id",$=>r+"-exclude-"+$.start.format("YYYY-MM-DD")).attr("x",$=>M($.start.startOf("day"))+v).attr("y",a.gridLineStartPadding).attr("width",$=>M($.end.endOf("day"))-M($.start.startOf("day"))).attr("height",f-p-a.gridLineStartPadding).attr("transform-origin",function($,I){return(M($.start)+v+.5*(M($.end)-M($.start))).toString()+"px "+(I*u+.5*f).toString()+"px"}).attr("class","exclude-range")}c(Y,"drawExcludeDays");function N(u,p,v,g){if(v<=0||u>p)return 1/0;const f=p-u,o=X.duration({[g??"day"]:v}).asMilliseconds();return o<=0?1/0:Math.ceil(f/o)}c(N,"getEstimatedTickCount");function G(u,p,v,g){const f=i.db.getDateFormat(),o=i.db.getAxisFormat();let l;o?l=o:f==="D"?l="%d":l=a.axisFormat??"%Y-%m-%d";let h=ve(M).tickSize(-g+p+a.gridLineStartPadding).tickFormat(Ht(l));const x=/^([1-9]\d*)(millisecond|second|minute|hour|day|week|month)$/.exec(i.db.getTickInterval()||a.tickInterval);if(x!==null){const s=parseInt(x[1],10);if(isNaN(s)||s<=0)nt.warn(`Invalid tick interval value: "${x[1]}". Skipping custom tick interval.`);else{const P=x[2],e=i.db.getWeekday()||a.weekday,w=M.domain(),L=w[0],$=w[1],I=N(L,$,s,P);if(I>Ct)nt.warn(`The tick interval "${s}${P}" would generate ${I} ticks, which exceeds the maximum allowed (${Ct}). This may indicate an invalid date or time range. Skipping custom tick interval.`);else switch(P){case"millisecond":h.ticks(Ut.every(s));break;case"second":h.ticks(qt.every(s));break;case"minute":h.ticks(Xt.every(s));break;case"hour":h.ticks(jt.every(s));break;case"day":h.ticks(Gt.every(s));break;case"week":h.ticks(Kt[e].every(s));break;case"month":h.ticks(Bt.every(s));break}}}if(R.append("g").attr("class","grid").attr("transform","translate("+u+", "+(g-50)+")").call(h).selectAll("text").style("text-anchor","middle").attr("fill","#000").attr("stroke","none").attr("font-size",10).attr("dy","1em"),i.db.topAxisEnabled()||a.topAxis){let s=pe(M).tickSize(-g+p+a.gridLineStartPadding).tickFormat(Ht(l));if(x!==null){const P=parseInt(x[1],10);if(isNaN(P)||P<=0)nt.warn(`Invalid tick interval value: "${x[1]}". Skipping custom tick interval.`);else{const e=x[2],w=i.db.getWeekday()||a.weekday,L=M.domain(),$=L[0],I=L[1];if(N($,I,P,e)<=Ct)switch(e){case"millisecond":s.ticks(Ut.every(P));break;case"second":s.ticks(qt.every(P));break;case"minute":s.ticks(Xt.every(P));break;case"hour":s.ticks(jt.every(P));break;case"day":s.ticks(Gt.every(P));break;case"week":s.ticks(Kt[w].every(P));break;case"month":s.ticks(Bt.every(P));break}}}R.append("g").attr("class","grid").attr("transform","translate("+u+", "+p+")").call(s).selectAll("text").style("text-anchor","middle").attr("fill","#000").attr("stroke","none").attr("font-size",10)}}c(G,"makeGrid");function E(u,p){let v=0;const g=Object.keys(V).map(f=>[f,V[f]]);R.append("g").selectAll("text").data(g).enter().append(function(f){const o=f[0].split(Te.lineBreakRegex),l=-(o.length-1)/2,h=O.createElementNS("http://www.w3.org/2000/svg","text");h.setAttribute("dy",l+"em");for(const[m,x]of o.entries()){const s=O.createElementNS("http://www.w3.org/2000/svg","tspan");s.setAttribute("alignment-baseline","central"),s.setAttribute("x","10"),m>0&&s.setAttribute("dy","1em"),s.textContent=x,h.appendChild(s)}return h}).attr("x",10).attr("y",function(f,o){if(o>0)for(let l=0;l<o;l++)return v+=g[o-1][1],f[1]*u/2+v*u+p;else return f[1]*u/2+p}).attr("font-size",a.sectionFontSize).attr("class",function(f){for(const[o,l]of F.entries())if(f[0]===l)return"sectionTitle sectionTitle"+o%a.numberSectionStyles;return"sectionTitle"})}c(E,"vertLabels");function T(u,p,v,g){const f=i.db.getTodayMarker();if(f==="off")return;const o=R.append("g").attr("class","today"),l=new Date,h=o.append("line");h.attr("x1",M(l)+u).attr("x2",M(l)+u).attr("y1",a.titleTopMargin).attr("y2",g-a.titleTopMargin).attr("class","today"),f!==""&&h.attr("style",f.replace(/,/g,";"))}c(T,"drawToday");function d(u){const p={},v=[];for(let g=0,f=u.length;g<f;++g)Object.prototype.hasOwnProperty.call(p,u[g])||(p[u[g]]=!0,v.push(u[g]));return v}c(d,"checkUnique")},"draw"),$s={setConf:Ms,draw:Is},Ys=c(t=>`
  .mermaid-main-font {
        font-family: ${t.fontFamily};
  }

  .exclude-range {
    fill: ${t.excludeBkgColor};
  }

  .section {
    stroke: none;
    opacity: 0.2;
  }

  .section0 {
    fill: ${t.sectionBkgColor};
  }

  .section2 {
    fill: ${t.sectionBkgColor2};
  }

  .section1,
  .section3 {
    fill: ${t.altSectionBkgColor};
    opacity: 0.2;
  }

  .sectionTitle0 {
    fill: ${t.titleColor};
  }

  .sectionTitle1 {
    fill: ${t.titleColor};
  }

  .sectionTitle2 {
    fill: ${t.titleColor};
  }

  .sectionTitle3 {
    fill: ${t.titleColor};
  }

  .sectionTitle {
    text-anchor: start;
    font-family: ${t.fontFamily};
  }


  /* Grid and axis */

  .grid .tick {
    stroke: ${t.gridColor};
    opacity: 0.8;
    shape-rendering: crispEdges;
  }

  .grid .tick text {
    font-family: ${t.fontFamily};
    fill: ${t.textColor};
  }

  .grid path {
    stroke-width: 0;
  }


  /* Today line */

  .today {
    fill: none;
    stroke: ${t.todayLineColor};
    stroke-width: 2px;
  }


  /* Task styling */

  /* Default task */

  .task {
    stroke-width: 2;
  }

  .taskText {
    text-anchor: middle;
    font-family: ${t.fontFamily};
  }

  .taskTextOutsideRight {
    fill: ${t.taskTextDarkColor};
    text-anchor: start;
    font-family: ${t.fontFamily};
  }

  .taskTextOutsideLeft {
    fill: ${t.taskTextDarkColor};
    text-anchor: end;
  }


  /* Special case clickable */

  .task.clickable {
    cursor: pointer;
  }

  .taskText.clickable {
    cursor: pointer;
    fill: ${t.taskTextClickableColor} !important;
    font-weight: bold;
  }

  .taskTextOutsideLeft.clickable {
    cursor: pointer;
    fill: ${t.taskTextClickableColor} !important;
    font-weight: bold;
  }

  .taskTextOutsideRight.clickable {
    cursor: pointer;
    fill: ${t.taskTextClickableColor} !important;
    font-weight: bold;
  }


  /* Specific task settings for the sections*/

  .taskText0,
  .taskText1,
  .taskText2,
  .taskText3 {
    fill: ${t.taskTextColor};
  }

  .task0,
  .task1,
  .task2,
  .task3 {
    fill: ${t.taskBkgColor};
    stroke: ${t.taskBorderColor};
  }

  .taskTextOutside0,
  .taskTextOutside2
  {
    fill: ${t.taskTextOutsideColor};
  }

  .taskTextOutside1,
  .taskTextOutside3 {
    fill: ${t.taskTextOutsideColor};
  }


  /* Active task */

  .active0,
  .active1,
  .active2,
  .active3 {
    fill: ${t.activeTaskBkgColor};
    stroke: ${t.activeTaskBorderColor};
  }

  .activeText0,
  .activeText1,
  .activeText2,
  .activeText3 {
    fill: ${t.taskTextDarkColor} !important;
  }


  /* Completed task */

  .done0,
  .done1,
  .done2,
  .done3 {
    stroke: ${t.doneTaskBorderColor};
    fill: ${t.doneTaskBkgColor};
    stroke-width: 2;
  }

  .doneText0,
  .doneText1,
  .doneText2,
  .doneText3 {
    fill: ${t.taskTextDarkColor} !important;
  }

  /* Done task text displayed outside the bar sits against the diagram background,
     not against the done-task bar, so it must use the outside/contrast color. */
  .doneText0.taskTextOutsideLeft,
  .doneText0.taskTextOutsideRight,
  .doneText1.taskTextOutsideLeft,
  .doneText1.taskTextOutsideRight,
  .doneText2.taskTextOutsideLeft,
  .doneText2.taskTextOutsideRight,
  .doneText3.taskTextOutsideLeft,
  .doneText3.taskTextOutsideRight {
    fill: ${t.taskTextOutsideColor} !important;
  }


  /* Tasks on the critical line */

  .crit0,
  .crit1,
  .crit2,
  .crit3 {
    stroke: ${t.critBorderColor};
    fill: ${t.critBkgColor};
    stroke-width: 2;
  }

  .activeCrit0,
  .activeCrit1,
  .activeCrit2,
  .activeCrit3 {
    stroke: ${t.critBorderColor};
    fill: ${t.activeTaskBkgColor};
    stroke-width: 2;
  }

  .doneCrit0,
  .doneCrit1,
  .doneCrit2,
  .doneCrit3 {
    stroke: ${t.critBorderColor};
    fill: ${t.doneTaskBkgColor};
    stroke-width: 2;
    cursor: pointer;
    shape-rendering: crispEdges;
  }

  .milestone {
    transform: rotate(45deg) scale(0.8,0.8);
  }

  .milestoneText {
    font-style: italic;
  }
  .doneCritText0,
  .doneCritText1,
  .doneCritText2,
  .doneCritText3 {
    fill: ${t.taskTextDarkColor} !important;
  }

  /* Done-crit task text outside the bar — same reasoning as doneText above. */
  .doneCritText0.taskTextOutsideLeft,
  .doneCritText0.taskTextOutsideRight,
  .doneCritText1.taskTextOutsideLeft,
  .doneCritText1.taskTextOutsideRight,
  .doneCritText2.taskTextOutsideLeft,
  .doneCritText2.taskTextOutsideRight,
  .doneCritText3.taskTextOutsideLeft,
  .doneCritText3.taskTextOutsideRight {
    fill: ${t.taskTextOutsideColor} !important;
  }

  .vert {
    stroke: ${t.vertLineColor};
  }

  .vertText {
    font-size: 15px;
    text-anchor: middle;
    fill: ${t.vertLineColor} !important;
  }

  .activeCritText0,
  .activeCritText1,
  .activeCritText2,
  .activeCritText3 {
    fill: ${t.taskTextDarkColor} !important;
  }

  .titleText {
    text-anchor: middle;
    font-size: 18px;
    fill: ${t.titleColor||t.textColor};
    font-family: ${t.fontFamily};
  }
`,"getStyles"),Ls=Ys,Ws={parser:Be,db:Cs,renderer:$s,styles:Ls};export{Ws as diagram};
