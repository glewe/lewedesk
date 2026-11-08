import"./fonts-DulLi24i.js";import{t as u,e as s,_ as o}from"./lookups-C_5IjfOa.js";import"./main-C5OaDeha.js";const d="releaseinfo.json",m="https://github.com/glewe/lewedesk/issues/",b=[["bugfixes","about.bugfixes"],["improvements","about.improvements"],["features","about.features"],["removals","about.removals"]],p=e=>{const t=String(e??"").trim();return/^https?:\/\//i.test(t)?t:/^#?\d+$/.test(t)?m+t.replace("#",""):""},f=e=>{const t=b.filter(([r])=>e[r]?.length).map(([r,i])=>{const l=e[r].map(a=>{const n=p(a.issue),c=n?` ( <a href="${s(n)}" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1"></i>${s(o("about.issue"))}</a> )`:"";return`<li>${s(a.summary)}${c}</li>`}).join("");return`<h6>${s(o(i))}</h6><ul>${l}</ul>`}).join("");return`
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between">
        <span><i class="bi bi-box me-2"></i>${s(o("about.release"))} ${s(e.version)}</span>
        <span><i class="bi bi-calendar me-2"></i>${s(e.date)}</span>
      </div>
      <div class="card-body">
        ${e.info?`<p>${s(e.info)}</p>`:""}
        ${t}
      </div>
    </div>`};document.addEventListener("DOMContentLoaded",async()=>{const e=document.querySelector("#release-list");if(e){await u;try{const t=await fetch(`${d}?v=${Date.now()}`);if(!t.ok)throw new Error(`HTTP ${t.status}`);const r=await t.json();e.innerHTML=r.map(f).join("")}catch(t){console.error("Failed to load the release information:",t),e.innerHTML=`<div class="alert alert-danger mb-0">${s(o("about.load_error"))}</div>`}}});
