import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { journalsApi, authApi } from "../../api/index.js";

const BULAN = ["JAN", "FEB", "MAR", "APR", "MEI", "JUN", "JUL", "AGU", "SEP", "OKT", "NOV", "DES"];

function badgeStatus(s) {
  if (s === "verified") return ["s-badge-green", "Disetujui"];
  if (s === "needs_revision") return ["s-badge-amber", "Revisi"];
  if (s === "submitted") return ["s-badge-blue", "Menunggu"];
  return ["s-badge-gray", "Draf"];
}

function judul(j) {
  return String(j.kegiatan || j.activity || "").split("\n")[0].slice(0, 60) || "Tanpa judul";
}
function subjudul(j) {
  const t = j.teknologi ? `${j.teknologi}` : "";
  const h = j.hasil ? ` • ${String(j.hasil).slice(0, 30)}` : "";
  return (t + h).replace(/^ • /, "") || "—";
}

/* ================= RIWAYAT JURNAL ================= */
function JurnalSiswa({ onMeta, placement }) {
  const navigate = useNavigate();
  const [journals, setJournals] = useState([]);
  const [loading, setLoading] = useState(true);
  const [bulan, setBulan] = useState("all");

  useEffect(() => {
    onMeta?.({ title: "Jurnal PKL", subtitle: "" });
    (async () => {
      try {
        const res = await journalsApi.index({ placement_id: placement?.id }).catch(() => ({ data: [] }));
        setJournals((res?.data ?? []).slice().sort((a, b) => String(b.journal_date).localeCompare(String(a.journal_date))));
      } finally { setLoading(false); }
    })();
  }, [onMeta, placement?.id]);

  const bulanTersedia = useMemo(() => {
    const s = new Set();
    journals.forEach((j) => { if (j.journal_date) s.add(String(j.journal_date).slice(0, 7)); });
    return [...s].sort().reverse();
  }, [journals]);

  const tampil = journals.filter((j) => bulan === "all" || String(j.journal_date).startsWith(bulan));

  const total = journals.length;
  const disetujui = journals.filter((j) => j.status === "verified").length;
  const revisi = journals.filter((j) => j.status === "needs_revision").length;
  const mingguTerakhir = new Set(
    journals.filter((j) => {
      const diff = (Date.now() - new Date(j.journal_date).getTime()) / 86400000;
      return diff >= 0 && diff < 35;
    }).map((j) => j.journal_date)
  ).size;

  const stats = [
    { lb: "Total Jurnal", vl: total, unit: "entri", hint: `${mingguTerakhir} minggu terakhir`, icon: "fa-book", bg: "#dbeafe", fg: "#1d4ed8" },
    { lb: "Disetujui", vl: disetujui, unit: "", hint: placement?.supervisor_name ? `Oleh ${placement.supervisor_name}` : "Oleh pembimbing", icon: "fa-circle-check", bg: "#dcfce7", fg: "#15803d" },
    { lb: "Perlu Revisi", vl: revisi, unit: "", hint: "Segera perbaiki & kirim ulang", icon: "fa-triangle-exclamation", bg: "#fef3c7", fg: "#b45309" },
  ];

  return (
    <div>
      <div className="siswa-grid3">
        {stats.map((s) => (
          <div className="siswa-stat" key={s.lb}>
            <div>
              <div className="siswa-stat-lb">{s.lb}</div>
              <div className="siswa-stat-vl">{s.vl}{s.unit && <small> {s.unit}</small>}</div>
              <div className="siswa-stat-hint">{s.hint}</div>
            </div>
            <span className="siswa-stat-ic" style={{ background: s.bg, color: s.fg }}>
              <i className={`fa-solid ${s.icon}`}></i>
            </span>
          </div>
        ))}
      </div>

      <div className="siswa-card">
        <div className="siswa-jurnal-head">
          <div>
            <h3 className="siswa-card-title">Riwayat Jurnal</h3>
            <div className="siswa-sub">Satu entri utama per tanggal</div>
          </div>
          <div className="siswa-jurnal-tools">
            <select className="siswa-select" style={{ width: 150 }} value={bulan} onChange={(e) => setBulan(e.target.value)}>
              <option value="all">Semua</option>
              {bulanTersedia.map((b) => {
                const [y, m] = b.split("-");
                return <option key={b} value={b}>{["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"][Number(m) - 1]} {y}</option>;
              })}
            </select>
            <button type="button" className="siswa-btn siswa-btn-primary siswa-btn-sm" onClick={() => navigate("/siswa/jurnal/tulis")}>
              <i className="fa-solid fa-plus"></i> Tulis Jurnal
            </button>
          </div>
        </div>

        {loading ? <p className="siswa-muted">Memuat…</p> : tampil.length === 0 ? (
          <div className="siswa-empty">
            <i className="fa-solid fa-book-open"></i>
            Belum ada jurnal. Mulai tulis jurnal pertamamu!
          </div>
        ) : tampil.map((j) => {
          const [cls, lb] = badgeStatus(j.status);
          const d = new Date(j.journal_date);
          return (
            <div className="siswa-jurnal-row" key={j.id}>
              <div className="siswa-datebox">
                <b>{d.getDate()}</b>
                <span>{BULAN[d.getMonth()]}</span>
              </div>
              <div className="siswa-jurnal-tx">
                <b>{judul(j)}</b>
                <small>{subjudul(j)}</small>
                {j.status === "needs_revision" && j.company_note && (
                  <div className="siswa-muted" style={{ fontSize: 12, color: "#b45309", marginTop: 4 }}>
                    <i className="fa-solid fa-comment-dots"></i> {j.company_note}
                  </div>
                )}
              </div>
              <span className={`siswa-badge ${cls}`}><span className="dot"></span>{lb}</span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

/* ================= TULIS JURNAL ================= */
export function TulisJurnal({ onMeta, placement }) {
  const navigate = useNavigate();
  const [form, setForm] = useState({
    journal_date: new Date().toISOString().slice(0, 10),
    kegiatan: "", hasil: "", teknologi: "", kendala: "", solusi: "",
  });
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState({ t: "", ok: true });

  useEffect(() => {
    onMeta?.({ title: "Tulis Jurnal", subtitle: "Ceritakan kegiatan PKL hari ini" });
  }, [onMeta]);

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const payload = () => ({
    placement_id: placement?.id,
    student_id: authApi.currentUser()?.id,
    journal_date: form.journal_date,
    kegiatan: form.kegiatan.trim(),
    hasil: form.hasil.trim(),
    teknologi: form.teknologi.trim(),
    kendala: form.kendala.trim(),
    solusi: form.solusi.trim(),
    activity: [form.kegiatan.trim(), form.hasil.trim()].filter(Boolean).join(" — "),
    status: "draft",
  });

  const valid = () => {
    if (!form.kegiatan.trim()) { setMsg({ t: "Kegiatan wajib diisi.", ok: false }); return false; }
    return true;
  };

  const simpanDraft = async () => {
    if (!valid()) return;
    setSaving(true);
    try {
      await journalsApi.store(payload());
      navigate("/siswa/jurnal");
    } catch (e) {
      setMsg({ t: e?.message || "Gagal menyimpan draf.", ok: false });
    } finally { setSaving(false); }
  };

  const kirimJurnal = async () => {
    if (!valid()) return;
    setSaving(true);
    try {
      await journalsApi.store({ ...payload(), status: "submitted", submitted_at: new Date().toISOString().slice(0, 19).replace("T", " ") });
      navigate("/siswa/jurnal");
    } catch (e) {
      setMsg({ t: e?.message || "Gagal mengirim jurnal.", ok: false });
    } finally { setSaving(false); }
  };

  const periksaAI = () => {
    if (!valid()) return;
    const draf = [form.kegiatan, form.hasil && `Hasil: ${form.hasil}`, form.kendala && `Kendala: ${form.kendala}`, form.solusi && `Solusi: ${form.solusi}`]
      .filter(Boolean).join(" ");
    navigate("/siswa/ai", { state: { drafAwal: draf, kembaliKe: "/siswa/jurnal/tulis" } });
  };

  return (
    <div>
      <div className="siswa-card">
        {msg.t && <div className={msg.ok ? "siswa-ok" : "siswa-err"}>{msg.t}</div>}
        <div className="siswa-form-grid">
          <label className="siswa-field">
            <span>Tanggal</span>
            <input type="date" className="siswa-input" value={form.journal_date} onChange={set("journal_date")} />
          </label>
          <label className="siswa-field">
            <span>Teknologi / Tools</span>
            <input className="siswa-input" value={form.teknologi} onChange={set("teknologi")}
              placeholder="cth: Laravel • Postman • Figma • Photoshop" />
          </label>
        </div>
        <label className="siswa-field">
          <span>Kegiatan</span>
          <textarea className="siswa-textarea" value={form.kegiatan} onChange={set("kegiatan")}
            placeholder="Ceritakan apa yang kamu kerjakan hari ini…" />
        </label>
        <label className="siswa-field">
          <span>Hasil</span>
          <textarea className="siswa-textarea" style={{ minHeight: 80 }} value={form.hasil} onChange={set("hasil")}
            placeholder="Apa hasil dari kegiatanmu?" />
        </label>
        <div className="siswa-form-grid">
          <label className="siswa-field">
            <span>Kendala</span>
            <textarea className="siswa-textarea" style={{ minHeight: 80 }} value={form.kendala} onChange={set("kendala")}
              placeholder="Kendala yang dihadapi (opsional)" />
          </label>
          <label className="siswa-field">
            <span>Solusi</span>
            <textarea className="siswa-textarea" style={{ minHeight: 80 }} value={form.solusi} onChange={set("solusi")}
              placeholder="Bagaimana kamu mengatasinya? (opsional)" />
          </label>
        </div>
        <div className="siswa-form-actions">
          <button type="button" className="siswa-btn siswa-btn-outline" disabled={saving} onClick={simpanDraft}>
            <i className="fa-solid fa-floppy-disk"></i> Simpan Draft
          </button>
          <button type="button" className="siswa-btn siswa-btn-outline" disabled={saving} onClick={periksaAI}>
            <i className="fa-solid fa-wand-magic-sparkles"></i> Periksa dengan AI
          </button>
          <button type="button" className="siswa-btn siswa-btn-primary" disabled={saving} onClick={kirimJurnal}>
            <i className="fa-solid fa-paper-plane"></i> {saving ? "Mengirim…" : "Kirim Jurnal"}
          </button>
        </div>
      </div>
    </div>
  );
}

export default JurnalSiswa;
