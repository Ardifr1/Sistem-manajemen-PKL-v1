import { useEffect, useMemo, useState } from "react";
import { Routes, Route, Navigate, useNavigate, useLocation } from "react-router-dom";
import { journalsApi, aiApi, authApi } from "../../api/index.js";

/* ---------- rapikan hasil revisi + jawaban siswa ---------- */
function rapikanTeks(teks) {
  let out = String(teks || "").trim();
  out = out
    .replace(/sudah dibenerin/gi, "berhasil saya perbaiki")
    .replace(/dibenerin/gi, "diperbaiki")
    .replace(/dikerjain/gi, "dikerjakan")
    .replace(/dilakuin/gi, "dilakukan");
  out = out.replace(/(^\s*\w|[.!?]\s+\w)/g, (m) => m.toUpperCase());
  out = out.replace(/\bapi\b/g, "API");
  return out;
}

function buatRevisiDariJawaban(revisiAi, pertanyaan, jawaban) {
  let out = rapikanTeks(revisiAi);
  const detail = [];

  (pertanyaan || []).forEach((q) => {
    const dipilih = (jawaban[q.id] || []).filter(Boolean);
    if (dipilih.length === 0) return;
    detail.push(dipilih.join(", "));
  });

  if (detail.length > 0) {
    if (!/[.!?]$/.test(out)) out += ".";
    out += ` Detail kegiatan: ${detail.join("; ")}.`;
  }

  return out;
}

/* ================= LANGKAH 1: input draf ================= */
function InputDraf({ draf, setDraf, onAnalisis, analyzing, aiError }) {
  return (
    <div className="siswa-ai-grid">
      <div className="siswa-card">
        <div className="siswa-between" style={{ marginBottom: 12 }}>
          <h3 className="siswa-card-title">Draf Jurnal</h3>
          <span className="siswa-badge s-badge-gray"><span className="dot"></span>{analyzing ? "AI memeriksa…" : "Belum dianalisis"}</span>
        </div>
        <textarea className="siswa-textarea" style={{ minHeight: 220 }} value={draf}
          onChange={(e) => setDraf(e.target.value)}
          placeholder="Tulis draf jurnalmu di sini…" />
        <p className="siswa-muted" style={{ margin: "12px 0 16px" }}>
          Tulis apa adanya — AI membantu merapikan, bukan mengganti isinya.
        </p>
        {aiError && <div className="siswa-err">{aiError}</div>}
        <button type="button" className="siswa-btn siswa-btn-primary siswa-btn-block"
          disabled={!draf.trim() || analyzing} onClick={onAnalisis}>
          <i className="fa-solid fa-wand-magic-sparkles"></i> {analyzing ? "Menganalisis…" : "Analisis dengan AI"}
        </button>
      </div>
      <div>
        <div className="siswa-card">
          <h3 className="siswa-card-title" style={{ marginBottom: 6 }}>Cara Kerja</h3>
          {[
            ["Tulis draf", "Ceritakan kegiatan harianmu apa adanya"],
            ["AI menganalisis", "Struktur, kejelasan, dan kelengkapan draf"],
            ["Jawab pertanyaan", "AI bertanya yang belum jelas dari drafmu"],
            ["Terapkan & kirim", "Hasil revisi singkat, kamu yang memutuskan"],
          ].map(([t, d], i) => (
            <div className="siswa-langkah" key={i}>
              <span className="siswa-langkah-n">{i + 1}</span>
              <div><b>{t}</b><small>{d}</small></div>
            </div>
          ))}
        </div>
        <div className="siswa-banner gray">
          <span className="b-ic"><i className="fa-solid fa-circle-info"></i></span>
          <div><b>AI hanya merapikan bahasa.</b> Tidak mengarang kegiatan, tidak menentukan nilai.</div>
        </div>
      </div>
    </div>
  );
}

/* ================= LANGKAH 2: pertanyaan AI ================= */
function PertanyaanAI({ analysis, tanggalLabel, onKembali, onBuatRevisi }) {
  const pertanyaan = analysis?.questions || [];
  const [jawaban, setJawaban] = useState({});

  const toggleMulti = (id, opsi) => {
    setJawaban((j) => {
      const cur = j[id] || [];
      return { ...j, [id]: cur.includes(opsi) ? cur.filter((x) => x !== opsi) : [...cur, opsi] };
    });
  };

  const judulDraf = analysis?.summary || "Draf jurnal";
  const belumJelas = analysis?.missing || [];

  return (
    <div className="siswa-card" style={{ maxWidth: 760 }}>
      <div className="siswa-between" style={{ marginBottom: 4 }}>
        <h3 className="siswa-card-title">Hasil Pemeriksaan AI</h3>
        <span className="siswa-badge s-badge-blue"><span className="dot"></span>{pertanyaan.length} pertanyaan</span>
      </div>
      <p className="siswa-sub" style={{ marginBottom: 18 }}>
        Draf jurnal {tanggalLabel} — &ldquo;{judulDraf}&rdquo;
      </p>

      <div className="siswa-ai-info">
        <p style={{ margin: "0 0 8px" }}>
          <b>Yang AI pahami:</b> {analysis?.summary || "-"}.
        </p>
        <p style={{ margin: 0 }}>
          <b>Yang belum jelas:</b>{" "}
          {belumJelas.length > 0
            ? `${belumJelas.join(", ")} — jawab di bawah ya.`
            : "tidak ada; draf sudah cukup lengkap."}
        </p>
      </div>

      {pertanyaan.length === 0 && (
        <div className="siswa-banner gray">
          <span className="b-ic"><i className="fa-solid fa-circle-info"></i></span>
          <div>AI tidak menemukan bagian yang perlu ditanyakan. Kamu bisa langsung membuat revisi.</div>
        </div>
      )}

      {pertanyaan.map((p, i) => (
        <div key={p.id} className="siswa-ai-q">
          <b>{i + 1}. {p.question}</b>
          {p.hint && <small>{p.hint}</small>}
          <div className="siswa-ai-pills">
            {(p.options || []).map((opsi) => {
              const selected = (jawaban[p.id] || []).includes(opsi);
              return (
                <label key={opsi} className={`siswa-ai-pill ${selected ? "on" : ""}`}>
                  <input
                    type="checkbox"
                    checked={selected}
                    onChange={() => toggleMulti(p.id, opsi)}
                  />
                  <span>{opsi}</span>
                </label>
              );
            })}
          </div>
        </div>
      ))}

      <p className="siswa-muted" style={{ margin: "6px 0 18px" }}>
        Centang sesuai kenyataan. AI menyempurnakan jurnal berdasarkan jawabanmu — tidak mengarang kegiatan.
      </p>

      <div className="siswa-form-actions">
        <button type="button" className="siswa-btn siswa-btn-outline" onClick={onKembali}>
          Kembali
        </button>
        <button type="button" className="siswa-btn siswa-btn-primary" onClick={() => onBuatRevisi(jawaban)}>
          <i className="fa-solid fa-wand-magic-sparkles"></i> Buat Revisi dari Jawabanku
        </button>
      </div>
    </div>
  );
}

/* ================= LANGKAH 3: hasil revisi ================= */
function HasilRevisi({ draf, revisi, onEditManual, onKirim }) {
  const navigate = useNavigate();
  return (
    <div>
      <div className="siswa-compare">
        <div className="siswa-card">
          <div className="siswa-between" style={{ marginBottom: 12 }}>
            <h3 className="siswa-card-title">Versi Asli</h3>
            <span className="siswa-badge s-badge-gray"><span className="dot"></span>Drafmu</span>
          </div>
          <p>{draf}</p>
        </div>
        <div className="siswa-card">
          <div className="siswa-between" style={{ marginBottom: 12 }}>
            <h3 className="siswa-card-title">Versi Revisi AI</h3>
            <span className="siswa-badge s-badge-green"><span className="dot"></span>Siap dikirim</span>
          </div>
          <p>{revisi}</p>
        </div>
      </div>
      <div className="siswa-banner amber siswa-mt">
        <span className="b-ic"><i className="fa-solid fa-triangle-exclamation"></i></span>
        <div style={{ flex: 1 }}>
          Jika ada bagian yang <b>tidak sesuai kenyataan</b>, pilih <b>Edit Manual</b> sebelum mengirim.
        </div>
        <div style={{ display: "flex", gap: 10 }}>
          <button type="button" className="siswa-btn siswa-btn-outline siswa-btn-sm" onClick={onEditManual}>
            Edit Manual
          </button>
          <button type="button" className="siswa-btn siswa-btn-primary siswa-btn-sm" onClick={onKirim}>
            Kirim Jurnal
          </button>
        </div>
      </div>
      <button type="button" className="siswa-btn siswa-btn-outline siswa-btn-sm" onClick={() => navigate("/siswa/ai/pertanyaan")}>
        <i className="fa-solid fa-arrow-left"></i> Kembali ke Pertanyaan
      </button>
    </div>
  );
}

/* ================= LANGKAH 4: konfirmasi kirim ================= */
function KonfirmasiKirim({ revisi, placement, tanggal, onKembali }) {
  const navigate = useNavigate();
  const [sending, setSending] = useState(false);
  const [err, setErr] = useState("");

  const kirim = async () => {
    if (!placement?.id) {
      setErr("Kamu belum memiliki penempatan PKL aktif. Hubungi admin sekolah.");
      return;
    }

    setSending(true);
    setErr("");
    try {
      const res = await journalsApi.store({
        placement_id: placement.id,
        student_id: authApi.currentUser()?.id,
        journal_date: tanggal,
        activity: revisi,
        ai_suggestion: "Diterapkan",
        revised_activity: revisi,
      });

      // Simpan sebagai draf lalu kirim untuk diverifikasi pembimbing industri;
      // AI tidak pernah menyetujui jurnal.
      const journalId = res?.data?.id;
      if (journalId) {
        await journalsApi.submit(journalId);
      }

      navigate("/siswa/jurnal");
    } catch (e) {
      setErr(e?.message || "Gagal mengirim jurnal.");
    } finally { setSending(false); }
  };

  const tglFmt = new Date(tanggal).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" });

  return (
    <div style={{ maxWidth: 760 }}>
      <div className="siswa-card">
        <div className="siswa-between" style={{ marginBottom: 4 }}>
          <h3 className="siswa-card-title">Ringkasan Jurnal</h3>
          <span className="siswa-badge s-badge-blue"><span className="dot"></span>Revisi AI diterapkan</span>
        </div>
        <p className="siswa-sub" style={{ marginBottom: 8 }}>{tglFmt} • {placement?.company_name || "Perusahaan PKL"}</p>
        <div className="siswa-kv"><span>Tanggal</span><b>{tglFmt}</b></div>
        <div className="siswa-kv"><span>Kegiatan</span><b>{revisi.slice(0, 80)}{revisi.length > 80 ? "…" : ""}</b></div>
        <div className="siswa-kv"><span>Isi Jurnal</span><b style={{ fontWeight: 400, maxWidth: "60%" }}>{revisi.slice(0, 160)}{revisi.length > 160 ? "…" : ""}</b></div>
      </div>

      <div className="siswa-banner blue">
        <span className="b-ic"><i className="fa-solid fa-square-check"></i></span>
        <div>
          Jurnal akan dikirim untuk <b>diverifikasi oleh {placement?.supervisor_name || "Pembimbing Industri"}</b> (Pembimbing Industri).
        </div>
      </div>

      {err && <div className="siswa-err">{err}</div>}

      <div style={{ display: "flex", gap: 12 }}>
        <button type="button" className="siswa-btn siswa-btn-outline" disabled={sending} onClick={onKembali}>
          Kembali
        </button>
        <button type="button" className="siswa-btn siswa-btn-primary" style={{ flex: 1 }} disabled={sending} onClick={kirim}>
          <i className="fa-solid fa-paper-plane"></i> {sending ? "Mengirim…" : "Kirim Sekarang"}
        </button>
      </div>
    </div>
  );
}

/* ================= PARENT ================= */
function AiAssistant({ onMeta, placement }) {
  const navigate = useNavigate();
  const location = useLocation();
  const [draf, setDraf] = useState(() => location.state?.drafAwal || "");
  const [analysis, setAnalysis] = useState(null);
  const [analyzing, setAnalyzing] = useState(false);
  const [aiError, setAiError] = useState("");
  const [siap, setSiap] = useState(false);
  const [revisi, setRevisi] = useState("");
  const [tanggal] = useState(new Date().toISOString().slice(0, 10));

  const tanggalLabel = useMemo(
    () => new Date(tanggal).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" }),
    [tanggal]
  );

  const metaFor = (p) => {
    if (p.includes("pertanyaan")) onMeta?.({ title: "Pertanyaan AI", subtitle: "AI sudah memeriksa drafmu — jawab dulu biar revisinya akurat" });
    else if (p.includes("hasil")) onMeta?.({ title: "Hasil Revisi AI", subtitle: "Periksa kembali — pastikan sesuai kegiatan sebenarnya" });
    else if (p.includes("konfirmasi")) onMeta?.({ title: "Konfirmasi Kirim", subtitle: "Periksa ringkasan jurnal sebelum dikirim" });
    else onMeta?.({ title: "AI Journal Assistant", subtitle: "Rapikan draf jurnal sebelum dikirim ke pembimbing industri" });
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { metaFor(location.pathname); }, [location.pathname]);

  const onAnalisis = async () => {
    if (!draf.trim() || analyzing) return;

    setAnalyzing(true);
    setAiError("");

    try {
      const res = await aiApi.review(draf);
      const data = res?.data;

      if (!data?.revised_activity) {
        setAiError("AI tidak mengembalikan saran yang bisa dipakai. Coba lagi.");
        return;
      }

      setAnalysis(data);
      setSiap(true);
      navigate("/siswa/ai/pertanyaan");
    } catch (e) {
      setAiError(e?.message || "Gagal memeriksa draf dengan AI. Coba lagi.");
    } finally {
      setAnalyzing(false);
    }
  };

  const onBuatRevisi = (jawaban) => {
    if (!analysis?.revised_activity) return;
    setRevisi(buatRevisiDariJawaban(analysis.revised_activity, analysis.questions, jawaban));
    navigate("/siswa/ai/hasil");
  };

  return (
    <Routes>
      <Route index element={
        <InputDraf draf={draf} setDraf={setDraf} onAnalisis={onAnalisis}
          analyzing={analyzing} aiError={aiError} />
      } />
      <Route path="pertanyaan" element={
        !siap || !analysis
          ? <Navigate to="/siswa/ai" replace />
          : <PertanyaanAI analysis={analysis} tanggalLabel={tanggalLabel}
              onKembali={() => navigate("/siswa/ai")}
              onBuatRevisi={onBuatRevisi} />
      } />
      <Route path="hasil" element={
        !revisi
          ? <Navigate to="/siswa/ai" replace />
          : <HasilRevisi draf={draf} revisi={revisi}
              onEditManual={() => { setDraf(revisi); setSiap(false); navigate("/siswa/ai"); }}
              onKirim={() => navigate("/siswa/ai/konfirmasi")} />
      } />
      <Route path="konfirmasi" element={
        !revisi
          ? <Navigate to="/siswa/ai" replace />
          : <KonfirmasiKirim revisi={revisi} placement={placement} tanggal={tanggal}
              onKembali={() => navigate("/siswa/ai/hasil")} />
      } />
      <Route path="*" element={<Navigate to="/siswa/ai" replace />} />
    </Routes>
  );
}

export default AiAssistant;
