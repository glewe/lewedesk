import{e as n,_ as s,b,a as $}from"./lookups-C_5IjfOa.js";import{f as w,u as y}from"./issue-ui-BCK2TfYB.js";const g=["issue_created","issue_deleted","issue_restored","comment_added","comment_edited","comment_deleted","attachment_added","attachment_deleted","field_changed"],p={type:"issue-types",status:"status",resolution:"resolutions"},E=["comment_added","comment_edited"],L=e=>{if(!g.includes(e.event))return s("history.events.unknown");if(e.event==="field_changed"){const o=`history.fields.${e.field}`,a=s(o)!==o?s(o):String(e.field??"");return s("history.events.field_changed").replace("{field}",a)}if(e.event==="attachment_added"||e.event==="attachment_deleted")return s(`history.events.${e.event}`).replace("{name}",String((e.event==="attachment_added"?e.new_value:e.old_value)??""));const t=`history.events.${e.event}_internal`;return e.is_internal&&s(t)!==t?s(t):s(`history.events.${e.event}`)},v=(e,t)=>t===null||t===""?`<span class="text-body-secondary">${n(s("history.none"))}</span>`:p[e]?n(b(p[e],{key:t,name:t})):e==="access"?n(s(`issues.access.${t}`)):n(t),S=e=>{const t=n(L(e));return E.includes(e.event)&&e.comment_id!==null?`<a href="#comment-${Number(e.comment_id)}">${t}</a>`:t},x=e=>{if(e.length===0)return`<p class="text-body-secondary mb-0">${n(s("history.empty"))}</p>`;const t=e.map(o=>`
        <tr data-event="${n(o.event)}">
          <td class="text-nowrap text-body-secondary">${n(w(o.created_at))}</td>
          <td>${y(o.actor)}</td>
          <td>${S(o)}</td>
          <td>${o.event==="field_changed"?v(o.field,o.old_value):""}</td>
          <td>${o.event==="field_changed"?v(o.field,o.new_value):""}</td>
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
    </div>`},N=e=>{const t=document.querySelector("#history-list"),o=`api/issues/${Number(e.id)}/history`;let a=[],d=!1,i=!0;const u=document.querySelector("#history-collapse"),m=document.querySelector("#history-chevron"),c=document.querySelector("#history-sort"),f=()=>{if(!c)return;const r=s(i?"history.sort_newest":"history.sort_oldest");c.title=r,c.setAttribute("aria-label",r),c.querySelector("i").className=`bi ${i?"bi-sort-down":"bi-sort-up"}`},l=()=>{if(t){if(c?.classList.toggle("d-none",d||a.length<2),f(),d){t.innerHTML=`<p class="text-danger mb-0">${n(s("history.load_error"))}</p>`;return}t.innerHTML=x(i?a:[...a].reverse())}};c?.addEventListener("click",()=>{i=!i,l()}),u?.addEventListener("show.bs.collapse",()=>m?.classList.replace("bi-chevron-right","bi-chevron-down")),u?.addEventListener("hide.bs.collapse",()=>m?.classList.replace("bi-chevron-down","bi-chevron-right"));const _=async()=>{try{const r=await $(o),h=await r.json().catch(()=>({}));if(!r.ok||h.status!=="success")throw new Error(h.message??r.statusText);a=h.data,d=!1}catch(r){console.error("Failed to load the history:",r),d=!0}l()};return window.addEventListener("languageChanged",l),_(),{reload:_}};export{L as e,N as i,v};
