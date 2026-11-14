import"./fonts-dgFHIs4h.js";import{t as _,a as h,_ as a,e as r,b as w}from"./branding-Syy4B4te.js";import{s as L,a as p,f as S}from"./sla-ui-DbtdFU8m.js";import{a as q,d as j,s as D}from"./main-CAI9vjJw.js";import{u as T}from"./issue-ui-BOnRPkZO.js";const l=["first_response","resolution"];let c=null;const u=e=>{const t=String(e.getMonth()+1).padStart(2,"0"),s=String(e.getDate()).padStart(2,"0");return`${e.getFullYear()}-${t}-${s}`},v=()=>{const e=document.querySelector("#report-filters");if(!e.reportValidity())return null;const t=new FormData(e);return new URLSearchParams({from:t.get("from"),to:t.get("to"),group_by:t.get("group_by"),tz:Intl.DateTimeFormat().resolvedOptions().timeZone||"UTC"})},g=e=>e==null?"&ndash;":`${Number(e).toLocaleString(void 0,{maximumFractionDigits:1})} %`,i=e=>e==null?"&ndash;":r(p(e)),y=e=>e==null?"secondary":e>=95?"success":e>=80?"warning":"danger",E=e=>{document.querySelector("#report-cards").innerHTML=l.map(t=>{const s=e[t],n=o=>r(a(`sla.report.${o}`));return`
      <div class="col-md-6" data-metric="${t}">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="fw-semibold">${r(a(`sla.metric.${t}`))}</div>
                <div class="small text-body-secondary">${n("compliance")}</div>
              </div>
              <div class="fs-2 fw-semibold lh-1 text-${y(s.compliance)}" data-field="compliance">${g(s.compliance)}</div>
            </div>
            <div class="row small g-2 mt-1">
              <div class="col-6 col-md-3"><div class="fw-semibold" data-field="total">${s.total}</div>${n("timers")}</div>
              <div class="col-6 col-md-3"><div class="fw-semibold text-success" data-field="in_time">${s.met_in_time}</div>${n("met_in_time")}</div>
              <div class="col-6 col-md-3"><div class="fw-semibold text-danger" data-field="breached">${s.met_late+s.breached_open}</div>${n("breached")}</div>
              <div class="col-6 col-md-3"><div class="fw-semibold" data-field="avg">${i(s.avg_seconds)}</div>${n("avg")}</div>
            </div>
            <div class="small text-body-secondary mt-2">${n("median")}: <span data-field="median">${i(s.median_seconds)}</span></div>
          </div>
        </div>
      </div>`}).join("")},k=(e,t)=>t.none?e==="assignee"?a("issues.unassigned"):a("dashboard_page.charts.no_product"):e==="priority"?w("priorities",t):t.name??"",M=e=>{document.querySelector("#groups-title").textContent=a("sla.report.by").replace("{group}",a(`sla.report.group.${e.group_by}`));const t=document.querySelector("#groups-table");if(e.groups.length===0){t.innerHTML=`<p class="text-body-secondary p-3 mb-0">${r(a("sla.report.empty"))}</p>`;return}const s=d=>`
    <td class="text-end border-start">${d.total}</td>
    <td class="text-end"><span class="fw-semibold text-${y(d.compliance)}">${g(d.compliance)}</span></td>
    <td class="text-end">${d.met_late+d.breached_open}</td>
    <td class="text-end">${i(d.avg_seconds)}</td>
    <td class="text-end">${i(d.median_seconds)}</td>`,n=l.map(()=>["timers","compliance","breached","avg","median"].map((d,m)=>`<th class="text-end${m===0?" border-start":""}">${r(a(`sla.report.${d}`))}</th>`).join("")).join(""),o=e.groups.map(d=>`
    <tr data-group="${r(d.id??"none")}">
      <td>${r(k(e.group_by,d))}</td>
      ${l.map(m=>s(d.stats[m])).join("")}
    </tr>`).join(""),x=`
    <tr class="fw-semibold table-group-divider" data-group="all">
      <td>${r(a("sla.report.all"))}</td>
      ${l.map(d=>s(e.totals[d])).join("")}
    </tr>`;t.innerHTML=`
    <div class="table-responsive">
      <table class="table table-sm small align-middle mb-0">
        <thead>
          <tr>
            <th rowspan="2">${r(a(`sla.report.group.${e.group_by}`))}</th>
            ${l.map(d=>`<th colspan="5" class="text-center border-start">${r(a(`sla.metric.${d}`))}</th>`).join("")}
          </tr>
          <tr>${n}</tr>
        </thead>
        <tbody>${o}${x}</tbody>
      </table>
    </div>`},R=e=>{const t=document.querySelector("#breached-table"),s=document.querySelector("#breached-note");if(s.textContent=e.breached_truncated?a("sla.report.truncated").replace("{shown}",String(e.breached.length)).replace("{total}",String(e.breached_total)):"",e.breached.length===0){t.innerHTML=`<p class="text-body-secondary p-3 mb-0">${r(a("sla.report.no_breached"))}</p>`;return}const n=e.breached.map(o=>`
    <tr>
      <td><a class="fw-semibold text-decoration-none" href="issue.html?id=${Number(o.issue.id)}">${r(o.issue.key)}</a> <span class="text-body-secondary">${r(o.issue.summary)}</span></td>
      <td>${r(a(`sla.metric.${o.metric}`))}</td>
      <td>${r(o.policy??"")}</td>
      <td>${o.assignee?T(o.assignee):`<span class="text-body-secondary">${r(a("issues.unassigned"))}</span>`}</td>
      <td class="text-end text-nowrap">${r(p(o.target_minutes*60))}</td>
      <td class="text-nowrap">${r(S(o.met_at??o.due_at))} <span class="text-body-secondary">(${r(a(o.met_at?"sla.report.met":"sla.report.open"))})</span></td>
      <td class="text-end text-nowrap text-danger">${o.overrun_seconds===null?"&ndash;":r(p(o.overrun_seconds))}</td>
    </tr>`).join("");t.innerHTML=`
    <div class="table-responsive">
      <table class="table table-sm small align-middle mb-0">
        <thead><tr>
          <th>${r(a("sla.report.issue"))}</th>
          <th>${r(a("sla.report.metric"))}</th>
          <th>${r(a("sla.report.policy"))}</th>
          <th>${r(a("sla.report.assignee"))}</th>
          <th class="text-end">${r(a("sla.report.target"))}</th>
          <th>${r(a("sla.report.outcome"))}</th>
          <th class="text-end">${r(a("sla.report.overrun"))}</th>
        </tr></thead>
        <tbody>${n}</tbody>
      </table>
    </div>`},f=e=>{document.querySelector("#report").classList.remove("d-none"),E(e.totals),M(e),R(e)},b=async()=>{const e=v();if(!e)return;const t=document.querySelector("#report-error");t.classList.add("d-none");try{const s=await h(`api/sla/reports?${e}`),n=await s.json().catch(()=>({}));if(!s.ok||n.status!=="success")throw new Error(n.message??s.statusText);c=n.data,f(c)}catch(s){console.error("Failed to load the SLA report:",s),c=null,document.querySelector("#report").classList.add("d-none"),t.textContent=s.message||a("sla.report.load_error"),t.classList.remove("d-none")}},$=async e=>{const t=v();if(t){t.set("format","csv"),t.set("part",e);try{const s=await h(`api/sla/reports?${t}`);if(!s.ok)throw new Error(s.statusText);const n=await s.blob(),o=document.createElement("a");o.href=URL.createObjectURL(n),o.download=`sla-${e}-${t.get("from")}_${t.get("to")}.csv`,document.body.append(o),o.click(),o.remove(),setTimeout(()=>URL.revokeObjectURL(o.href),1e3)}catch(s){console.error("Failed to export the SLA report:",s),D(r(a("sla.report.export_error")),"danger",a("common.error"))}}},C=()=>{document.addEventListener("DOMContentLoaded",async()=>{const e=document.querySelector("#report-filters");if(!e||(await _,await q,await j,!L()))return;const t=new Date,s=new Date(t);s.setDate(s.getDate()-29),e.elements.from.value=u(s),e.elements.to.value=u(t),e.addEventListener("submit",n=>{n.preventDefault(),b()}),document.querySelector("#export-summary-btn").addEventListener("click",()=>$("summary")),document.querySelector("#export-breached-btn").addEventListener("click",()=>$("breached")),window.addEventListener("languageChanged",()=>c&&f(c)),b()})};C();
