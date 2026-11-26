import{e as n,_ as s,b,a as $}from"./issue-key-C-pF9m5h.js";import{f as y}from"./main-Bklnr4Fx.js";import{f as w,u as g}from"./issue-ui-D7dZmueB.js";const E=["issue_created","issue_deleted","issue_restored","comment_added","comment_edited","comment_deleted","attachment_added","attachment_deleted","field_changed"],_={type:"issue-types",status:"status",resolution:"resolutions",priority:"priorities"},L=["comment_added","comment_edited"],S=e=>{if(!E.includes(e.event))return s("history.events.unknown");if(e.event==="field_changed"){const o=`history.fields.${e.field}`,a=s(o)!==o?s(o):String(e.field??"");return s("history.events.field_changed").replace("{field}",a)}if(e.event==="attachment_added"||e.event==="attachment_deleted")return s(`history.events.${e.event}`).replace("{name}",String((e.event==="attachment_added"?e.new_value:e.old_value)??""));const t=`history.events.${e.event}_internal`;return e.is_internal&&s(t)!==t?s(t):s(`history.events.${e.event}`)},f=(e,t)=>t===null||t===""?`<span class="text-body-secondary">${n(s("history.none"))}</span>`:_[e]?n(b(_[e],{key:t,name:t})):e==="access"?n(s(`issues.access.${t}`)):/^sla_/.test(e??"")&&!Number.isNaN(Date.parse(t))?n(y(t)):n(t),N=e=>{const t=n(S(e));return L.includes(e.event)&&e.comment_id!==null?`<a href="#comment-${Number(e.comment_id)}">${t}</a>`:t},x=e=>{if(e.length===0)return`<p class="text-body-secondary mb-0">${n(s("history.empty"))}</p>`;const t=e.map(o=>`
        <tr data-event="${n(o.event)}">
          <td class="text-nowrap text-body-secondary">${n(w(o.created_at))}</td>
          <td>${g(o.actor)}</td>
          <td>${N(o)}</td>
          <td>${o.event==="field_changed"?f(o.field,o.old_value):""}</td>
          <td>${o.event==="field_changed"?f(o.field,o.new_value):""}</td>
        </tr>`).join("");return`
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0 history-table">
        <thead>
          <tr>
            <th scope="col">${n(s("history.columns.when"))}</th>
            <th scope="col">${n(s("history.columns.who"))}</th>
            <th scope="col">${n(s("history.columns.what"))}</th>
            <th scope="col">${n(s("history.columns.from"))}</th>
            <th scope="col">${n(s("history.columns.to"))}</th>
          </tr>
        </thead>
        <tbody>${t}</tbody>
      </table>
    </div>`},q=e=>{const t=document.querySelector("#history-list"),o=`api/issues/${Number(e.id)}/history`;let a=[],d=!1,c=!0;const u=document.querySelector("#history-collapse"),m=document.querySelector("#history-chevron"),i=document.querySelector("#history-sort"),v=()=>{if(!i)return;const r=s(c?"history.sort_newest":"history.sort_oldest");i.title=r,i.setAttribute("aria-label",r),i.querySelector("i").className=`bi ${c?"bi-sort-down":"bi-sort-up"}`},l=()=>{if(t){if(i?.classList.toggle("d-none",d||a.length<2),v(),d){t.innerHTML=`<p class="text-danger mb-0">${n(s("history.load_error"))}</p>`;return}t.innerHTML=x(c?a:[...a].reverse())}};i?.addEventListener("click",()=>{c=!c,l()}),u?.addEventListener("show.bs.collapse",()=>m?.classList.replace("bi-chevron-right","bi-chevron-down")),u?.addEventListener("hide.bs.collapse",()=>m?.classList.replace("bi-chevron-down","bi-chevron-right"));const p=async()=>{try{const r=await $(o),h=await r.json().catch(()=>({}));if(!r.ok||h.status!=="success")throw new Error(h.message??r.statusText);a=h.data,d=!1}catch(r){console.error("Failed to load the history:",r),d=!0}l()};return window.addEventListener("languageChanged",l),p(),{reload:p}};export{S as e,q as i,f as v};
