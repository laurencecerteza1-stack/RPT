// ============================================
// reports.js — extracted from home.html
// ============================================

async function generateSummaryReport(){const s=document.getElementById("reportStart").value,e=document.getElementById("reportEnd").value;if(!s||!e)return alert("Select start and end date.");const start=new Date(s),end=new Date(e);end.setHours(23,59,59);const sel=Array.from(document.getElementById("reportUser").selectedOptions).map(o=>o.value.toUpperCase());if(!sel.length)return alert("Select at least one user.");const data=await safeFetch(CLOUD_URL);const filtered=data.filter(r=>{const d=new Date(r[0]);return d>=start&&d<=end&&sel.includes(r[2]?.toUpperCase());});if(!filtered.length)return alert("No records found.");let total=0;let html=`<table style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr style="background:var(--surface2)"><th style="padding:8px;text-align:left;border-bottom:1px solid var(--border);color:var(--muted)">Date</th><th style="padding:8px;text-align:left;border-bottom:1px solid var(--border);color:var(--muted)">User</th><th style="padding:8px;text-align:left;border-bottom:1px solid var(--border);color:var(--muted)">Lot</th><th style="padding:8px;text-align:right;border-bottom:1px solid var(--border);color:var(--muted)">Amount</th><th style="padding:8px;text-align:left;border-bottom:1px solid var(--border);color:var(--muted)">Status</th></tr></thead><tbody>`;filtered.forEach(r=>{total+=parseFloat(r[3]||0);let jd;try{jd=typeof r[4]==="string"?JSON.parse(r[4]):r[4];}catch{jd={};}const wLand=jd?.wLand||[];const status=wLand.length>0&&wLand[0][0]!==""?"WITH OR":"ESTIMATE";html+=`<tr><td style="padding:7px 8px;border-bottom:1px solid var(--border);color:var(--sub)">${formatPHDate(r[0])}</td><td style="padding:7px 8px;border-bottom:1px solid var(--border)">${r[2]}</td><td style="padding:7px 8px;border-bottom:1px solid var(--border);font-weight:500">${r[1]}</td><td style="padding:7px 8px;border-bottom:1px solid var(--border);text-align:right;color:#22c55e;font-weight:600">₱${Number(r[3]).toLocaleString()}</td><td style="padding:7px 8px;border-bottom:1px solid var(--border)"><span style="background:${status==="WITH OR"?"#14291e":"#1c1a10"};color:${status==="WITH OR"?"#86efac":"#fbbf24"};border-radius:5px;padding:2px 8px;font-size:11px">${status}</span></td></tr>`;});html+=`</tbody><tfoot><tr><td colspan="3" style="padding:10px 8px;font-weight:600;color:var(--text)">Total</td><td style="padding:10px 8px;font-weight:600;color:#22c55e;text-align:right">₱${total.toLocaleString()}</td><td></td></tr></tfoot></table>`;document.getElementById("reportContent").innerHTML=html;document.getElementById("reportResult").style.display="block";}

function printReport(){const c=document.getElementById("reportContent").innerHTML;const header=`<div style="text-align:center;margin-bottom:14px"><div style="font-size:15px;font-weight:700">Sta. Lucia Realty & Development, Inc. / Sta. Lucia Land, Inc.</div><div style="font-size:11px;color:#666;margin-top:2px">Generated: ${new Date().toLocaleString()}</div></div>`;const w=window.open("","","height=700,width=900");w.document.write("<html><head><style>body{font-family:Inter,sans-serif;padding:20px}table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid #eee;font-size:12px}</style></head><body>"+header+c+"</body></html>");w.document.close();setTimeout(()=>w.print(),500);}


let _chartMonthly,_chartUser,_chartProject;
async function generateInsights(){
  const s=document.getElementById("insightStart").value,e=document.getElementById("insightEnd").value;
  const data=await safeFetch(CLOUD_URL);
  let filtered=data;
  if(s&&e){const start=new Date(s),end=new Date(e);end.setHours(23,59,59);filtered=data.filter(r=>{const d=new Date(r[0]);return d>=start&&d<=end;});}
  if(!filtered.length)return alert("No records found.");

  const monthly={},byUser={},byProject={};
  filtered.forEach(r=>{
    const d=new Date(r[0]);
    const mKey=d.getFullYear()+"-"+String(d.getMonth()+1).padStart(2,"0");
    const amt=parseFloat(r[3]||0);
    monthly[mKey]=(monthly[mKey]||0)+amt;
    const user=(r[2]||"—").toUpperCase();
    byUser[user]=(byUser[user]||0)+amt;
    const proj=r[1]||"—";
    byProject[proj]=(byProject[proj]||0)+amt;
  });

  const mLabels=Object.keys(monthly).sort();
  const mData=mLabels.map(k=>monthly[k]);
  const uLabels=Object.keys(byUser).sort((a,b)=>byUser[b]-byUser[a]);
  const uData=uLabels.map(k=>byUser[k]);
  const pEntries=Object.entries(byProject).sort((a,b)=>b[1]-a[1]).slice(0,10);
  const pLabels=pEntries.map(x=>x[0]);
  const pData=pEntries.map(x=>x[1]);

  const palette=["#22c55e","#3b82f6","#f1c40f","#e74c3c","#9b59b6","#1abc9c","#e67e22","#34495e","#2ecc71","#95a5a6"];

  if(_chartMonthly)_chartMonthly.destroy();
  _chartMonthly=new Chart(document.getElementById("chartMonthly"),{type:"line",data:{labels:mLabels,datasets:[{label:"Collections",data:mData,borderColor:"#22c55e",backgroundColor:"rgba(34,197,94,.15)",fill:true,tension:.3}]},options:{maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>"₱"+Number(v).toLocaleString()}}}}});

  if(_chartUser)_chartUser.destroy();
  _chartUser=new Chart(document.getElementById("chartUser"),{type:"bar",data:{labels:uLabels,datasets:[{label:"Collections",data:uData,backgroundColor:palette,maxBarThickness:28}]},options:{maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>"₱"+Number(v).toLocaleString()}}}}});

  if(_chartProject)_chartProject.destroy();
  _chartProject=new Chart(document.getElementById("chartProject"),{type:"bar",data:{labels:pLabels,datasets:[{label:"Collections",data:pData,backgroundColor:palette,maxBarThickness:14}]},options:{indexAxis:"y",maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{callback:v=>"₱"+Number(v).toLocaleString()}}}}});
}
