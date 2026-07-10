import{g as j,s as J,a as Q,b as Y,q as tt,p as et,c as s,l as w,d as at,G as it,K as rt,L as st,M as G,e as ot,z as nt,N as lt,H as ct}from"./vendor-mermaid-CMiqNC-m.js";import{p as dt}from"./chunk-4BX2VUAB-m0twbF3Z.js";import{p as pt}from"./wardley-RL74JXVD-aNknZAr7.js";import{o as gt}from"./vendor-graph-C2U_okeF.js";import"./vendor-query-CmcMXlZA.js";var ht=ct.pie,C={sections:new Map,showData:!1},u=C.sections,D=C.showData,ut=structuredClone(ht),ft=s(()=>structuredClone(ut),"getConfig"),mt=s(()=>{u=new Map,D=C.showData,nt()},"clear"),vt=s(({label:t,value:a})=>{if(a<0)throw new Error(`"${t}" has invalid value: ${a}. Negative values are not allowed in pie charts. All slice values must be >= 0.`);u.has(t)||(u.set(t,a),w.debug(`added new section: ${t}, with value: ${a}`))},"addSection"),xt=s(()=>u,"getSections"),St=s(t=>{D=t},"setShowData"),wt=s(()=>D,"getShowData"),L={getConfig:ft,clear:mt,setDiagramTitle:et,getDiagramTitle:tt,setAccTitle:Y,getAccTitle:Q,setAccDescription:J,getAccDescription:j,addSection:vt,getSections:xt,setShowData:St,getShowData:wt},Ct=s((t,a)=>{dt(t,a),a.setShowData(t.showData),t.sections.map(a.addSection)},"populateDb"),Dt={parse:s(async t=>{const a=await pt("pie",t);w.debug(a),Ct(a,L)},"parse")},$t=s(t=>`
  .pieCircle{
    stroke: ${t.pieStrokeColor};
    stroke-width : ${t.pieStrokeWidth};
    opacity : ${t.pieOpacity};
  }
  .pieOuterCircle{
    stroke: ${t.pieOuterStrokeColor};
    stroke-width: ${t.pieOuterStrokeWidth};
    fill: none;
  }
  .pieTitleText {
    text-anchor: middle;
    font-size: ${t.pieTitleTextSize};
    fill: ${t.pieTitleTextColor};
    font-family: ${t.fontFamily};
  }
  .slice {
    font-family: ${t.fontFamily};
    fill: ${t.pieSectionTextColor};
    font-size:${t.pieSectionTextSize};
    // fill: white;
  }
  .legend text {
    fill: ${t.pieLegendTextColor};
    font-family: ${t.fontFamily};
    font-size: ${t.pieLegendTextSize};
  }
`,"getStyles"),yt=$t,Tt=s(t=>{const a=[...t.values()].reduce((r,n)=>r+n,0),$=[...t.entries()].map(([r,n])=>({label:r,value:n})).filter(r=>r.value/a*100>=1);return lt().value(r=>r.value).sort(null)($)},"createPieArcs"),At=s((t,a,$,y)=>{var W;w.debug(`rendering pie chart
`+t);const r=y.db,n=at(),T=it(r.getConfig(),n.pie),A=40,o=18,p=4,c=450,d=c,f=rt(a),l=f.append("g");l.attr("transform","translate("+d/2+","+c/2+")");const{themeVariables:i}=n;let[b]=st(i.pieOuterStrokeWidth);b??(b=2);const E=T.textPosition,g=Math.min(d,c)/2-A,B=G().innerRadius(0).outerRadius(g),N=G().innerRadius(g*E).outerRadius(g*E);l.append("circle").attr("cx",0).attr("cy",0).attr("r",g+b/2).attr("class","pieOuterCircle");const h=r.getSections(),O=Tt(h),P=[i.pie1,i.pie2,i.pie3,i.pie4,i.pie5,i.pie6,i.pie7,i.pie8,i.pie9,i.pie10,i.pie11,i.pie12];let m=0;h.forEach(e=>{m+=e});const _=O.filter(e=>(e.data.value/m*100).toFixed(0)!=="0"),v=gt(P).domain([...h.keys()]);l.selectAll("mySlices").data(_).enter().append("path").attr("d",B).attr("fill",e=>v(e.data.label)).attr("class","pieCircle"),l.selectAll("mySlices").data(_).enter().append("text").text(e=>(e.data.value/m*100).toFixed(0)+"%").attr("transform",e=>"translate("+N.centroid(e)+")").style("text-anchor","middle").attr("class","slice");const I=l.append("text").text(r.getDiagramTitle()).attr("x",0).attr("y",-400/2).attr("class","pieTitleText"),k=[...h.entries()].map(([e,S])=>({label:e,value:S})),x=l.selectAll(".legend").data(k).enter().append("g").attr("class","legend").attr("transform",(e,S)=>{const F=o+p,V=F*k.length/2,X=12*o,Z=S*F-V;return"translate("+X+","+Z+")"});x.append("rect").attr("width",o).attr("height",o).style("fill",e=>v(e.label)).style("stroke",e=>v(e.label)),x.append("text").attr("x",o+p).attr("y",o-p).text(e=>r.getShowData()?`${e.label} [${e.value}]`:e.label);const U=Math.max(...x.selectAll("text").nodes().map(e=>(e==null?void 0:e.getBoundingClientRect().width)??0)),q=d+A+o+p+U,R=((W=I.node())==null?void 0:W.getBoundingClientRect().width)??0,H=d/2-R/2,K=d/2+R/2,z=Math.min(0,H),M=Math.max(q,K)-z;f.attr("viewBox",`${z} 0 ${M} ${c}`),ot(f,c,M,T.useMaxWidth)},"draw"),bt={draw:At},Wt={parser:Dt,db:L,renderer:bt,styles:yt};export{Wt as diagram};
