import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { api } from './api.js';

const emptyForm = {
  title: '',
  unitId: '',
  types: [],
  reason: '',
  legalBasis: '',
  objective: '',
  description: '',
  status: 'Draft',
  documents: [],
};

const proposalStatuses = ['Draft', 'Menunggu Review', 'Sedang Direview', 'Perlu Revisi', 'Diajukan Kembali', 'Disetujui', 'Ditolak'];

const roleLabels = {
  admin: 'ADMIN',
  pengusul: 'PENGUSUL',
  reviewer: 'REVIEWER / BIRO HUKUM',
  biro_hukum: 'BIRO HUKUM',
  user: 'PENGUSUL',
};

const statusMeta = {
  Draft: { className: 'status-neutral', label: 'Draft' },
  'Menunggu Review': { className: 'status-info', label: 'Menunggu Review' },
  'Sedang Direview': { className: 'status-info', label: 'Sedang Direview' },
  'Perlu Revisi': { className: 'status-warning', label: 'Perlu Revisi' },
  'Diajukan Kembali': { className: 'status-info', label: 'Diajukan Kembali' },
  Disetujui: { className: 'status-success', label: 'Disetujui' },
  Ditolak: { className: 'status-danger', label: 'Ditolak' },
};

function App() {
  const [user, setUser] = useState(null);
  const [csrfToken, setCsrfToken] = useState('');
  const [items, setItems] = useState([]);
  const [lookups, setLookups] = useState({ units: [], regulationTypes: [], biroHukum: [] });
  const [profiles, setProfiles] = useState({ pengusul: [], reviewers: [] });
  const [masterData, setMasterData] = useState({ biroHukum: [], regulationTypes: [] });
  const [historyItems, setHistoryItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [activeView, setActiveView] = useState('Dashboard');
  const [editing, setEditing] = useState(null);
  const [showForm, setShowForm] = useState(false);
  const [showAccountForm, setShowAccountForm] = useState(false);
  const [accountType, setAccountType] = useState('pengusul');
  const [showMasterForm, setShowMasterForm] = useState(false);
  const [masterType, setMasterType] = useState('biro-hukum');
  const [activeNotes, setActiveNotes] = useState(null);
  const [noteText, setNoteText] = useState('');
  const [saving, setSaving] = useState(false);
  const saveModeRef = useRef('draft');

  const normalizedRole = user?.role === 'user' ? 'pengusul' : user?.role;
  const isPengusul = normalizedRole === 'pengusul';
  const isReviewer = normalizedRole === 'reviewer' || normalizedRole === 'biro_hukum';
  const isAdmin = normalizedRole === 'admin';

  const loadItems = useCallback(async (currentUser) => {
    const [options, result] = await Promise.all([api.lookups(), api.regulations()]);
    setLookups(options);
    setItems((result.items || []).map((item) => ({
      ...item,
      status: item.status || 'Draft',
      notes: Array.isArray(item.notes) ? item.notes : [],
      documents: Array.isArray(item.documents) ? item.documents : [],
      types: item.types || '',
    })));
    setHistoryItems((result.items || []).flatMap((item) => (item.statusHistory || []).map((entry) => ({
      ...entry,
      proposalNumber: item.proposalNumber,
      title: item.title,
    }))).sort((a, b) => new Date(b.date) - new Date(a.date)));
    if (currentUser?.role === 'admin') {
      const [proposers, reviewers, bureaus, regulationTypes] = await Promise.all([
        api.accounts('pengusul'),
        api.accounts('reviewers'),
        api.masterData('biro-hukum'),
        api.masterData('jenis-peraturan'),
      ]);
      setProfiles({ pengusul: proposers.items || [], reviewers: reviewers.items || [] });
      setMasterData({ biroHukum: bureaus.items || [], regulationTypes: regulationTypes.items || [] });
    }
  }, []);

  useEffect(() => {
    api.session()
      .then(({ user: currentUser, csrfToken: token }) => {
        setUser(currentUser);
        setCsrfToken(token);
        if (currentUser) return loadItems(currentUser);
      })
      .catch((requestError) => setError(requestError.message))
      .finally(() => setLoading(false));
  }, [loadItems]);

  const filteredItems = useMemo(() => {
    const normalized = query.trim().toLocaleLowerCase();
    return items.filter((item) => {
      const matchesQuery = !normalized || [item.title, item.proposalNumber].some((value) => String(value || '').toLocaleLowerCase().includes(normalized));
      const matchesStatus = !statusFilter || item.status === statusFilter;
      const itemTypes = String(item.types || '').split(', ').filter(Boolean);
      const matchesType = !typeFilter || itemTypes.includes(typeFilter);
      const matchesQueue = activeView !== 'Usulan Perlu Review'
        || ['Menunggu Review', 'Sedang Direview', 'Diajukan Kembali'].includes(item.status);
      return matchesQuery && matchesStatus && matchesType && matchesQueue;
    });
  }, [items, query, statusFilter, typeFilter, activeView]);

  const stats = useMemo(() => {
    const totals = {
      total: items.length,
      draft: items.filter((item) => item.status === 'Draft').length,
      waiting: items.filter((item) => item.status === 'Menunggu Review').length,
      review: items.filter((item) => item.status === 'Sedang Direview').length,
      needsRevision: items.filter((item) => item.status === 'Perlu Revisi').length,
      submittedAgain: items.filter((item) => item.status === 'Diajukan Kembali').length,
      approved: items.filter((item) => item.status === 'Disetujui').length,
      rejected: items.filter((item) => item.status === 'Ditolak').length,
    };

    if (isPengusul) {
      return [
        { label: 'Total Usulan', value: totals.total, tone: 'green', icon: '▤' },
        { label: 'Draft', value: totals.draft, tone: 'neutral', icon: '◷' },
        { label: 'Menunggu Review', value: totals.waiting, tone: 'blue', icon: '◌' },
        { label: 'Sedang Direview', value: totals.review, tone: 'blue', icon: '⌕' },
        { label: 'Perlu Revisi', value: totals.needsRevision, tone: 'amber', icon: '↺' },
        { label: 'Diajukan Kembali', value: totals.submittedAgain, tone: 'blue', icon: '↗' },
        { label: 'Disetujui', value: totals.approved, tone: 'green', icon: '✓' },
        { label: 'Ditolak', value: totals.rejected, tone: 'red', icon: '×' },
      ];
    }

    if (isReviewer) {
      return [
        { label: 'Total Usulan', value: totals.total, tone: 'green' },
        { label: 'Menunggu Review', value: totals.waiting, tone: 'blue' },
        { label: 'Sedang Direview', value: totals.review, tone: 'blue' },
        { label: 'Perlu Revisi', value: totals.needsRevision, tone: 'amber' },
        { label: 'Disetujui', value: totals.approved, tone: 'green' },
        { label: 'Ditolak', value: totals.rejected, tone: 'red' },
      ];
    }

    return [
      { label: 'Total Pengusul', value: profiles.pengusul.length, tone: 'blue' },
      { label: 'Total Reviewer', value: profiles.reviewers.length, tone: 'blue' },
      { label: 'Total Usulan', value: totals.total, tone: 'green' },
      { label: 'Menunggu Review', value: totals.waiting, tone: 'amber' },
      { label: 'Perlu Revisi', value: totals.needsRevision, tone: 'amber' },
      { label: 'Disetujui', value: totals.approved, tone: 'green' },
      { label: 'Ditolak', value: totals.rejected, tone: 'red' },
    ];
  }, [isPengusul, isReviewer, items, profiles]);

  async function handleLogin(event) {
    event.preventDefault();
    setError('');
    setSaving(true);
    const formData = new FormData(event.currentTarget);
    try {
      const result = await api.login({ username: formData.get('username'), password: formData.get('password') }, csrfToken);
      setUser(result.user);
      setCsrfToken(result.csrfToken);
      setActiveView('Dashboard');
      await loadItems(result.user);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSaving(false);
    }
  }

  async function handleLogout() {
    setError('');
    try {
      await api.logout(csrfToken);
      setUser(null);
      setItems([]);
      setProfiles({ pengusul: [], reviewers: [] });
      setMasterData({ biroHukum: [], regulationTypes: [] });
      setActiveView('Dashboard');
      const session = await api.session();
      setCsrfToken(session.csrfToken);
    } catch (requestError) {
      setError(requestError.message);
    }
  }

  async function handleChangePassword(event) {
    event.preventDefault();
    setError('');
    setNotice('');
    const form = event.currentTarget;
    const formData = new FormData(form);
    setSaving(true);
    try {
      await api.changePassword({
        currentPassword: formData.get('currentPassword'),
        newPassword: formData.get('newPassword'),
        confirmPassword: formData.get('confirmPassword'),
      }, csrfToken);
      form.reset();
      setNotice('Password berhasil diubah.');
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSaving(false);
    }
  }

  function openCreate() {
    saveModeRef.current = 'draft';
    setEditing({ ...emptyForm, id: null });
    setShowForm(true);
  }

  function openAccountCreate(type) {
    setAccountType(type);
    setShowAccountForm(true);
  }

  async function handleAccountSave(event) {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    const body = {
      action: 'create',
      profile: {
        name: formData.get('name'),
        unitId: formData.get('unitId'),
        bureauId: formData.get('bureauId'),
        position: formData.get('position'),
        email: formData.get('email'),
        phone: formData.get('phone'),
        status: formData.get('status'),
      },
      account: {
        username: formData.get('username'),
        password: formData.get('password'),
      },
    };
    setSaving(true);
    setError('');
    try {
      await api.saveAccount(accountType, body, csrfToken);
      await loadItems(user);
      setShowAccountForm(false);
      setNotice(`${accountType === 'pengusul' ? 'Akun Pengusul' : 'Akun Reviewer'} berhasil dibuat.`);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSaving(false);
    }
  }

  function openMasterCreate(type) {
    setMasterType(type);
    setShowMasterForm(true);
  }

  async function handleMasterSave(event) {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    const body = {
      action: 'create',
      name: formData.get('name'),
      code: formData.get('code'),
      description: formData.get('description'),
    };
    setSaving(true);
    setError('');
    try {
      await api.saveMasterData(masterType, body, csrfToken);
      await loadItems(user);
      setShowMasterForm(false);
      setNotice('Data master berhasil ditambahkan.');
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSaving(false);
    }
  }

  async function handleMasterStatus(entry) {
    const nextStatus = entry.status === 'aktif' ? 'nonaktif' : 'aktif';
    try {
      const type = activeView === 'Biro Hukum' ? 'biro-hukum' : 'jenis-peraturan';
      await api.saveMasterData(type, { action: 'set_status', id: entry.id, status: nextStatus }, csrfToken);
      await loadItems(user);
      setNotice('Status data master berhasil diperbarui.');
    } catch (requestError) {
      setError(requestError.message);
    }
  }

  async function handleAccountStatus(type, profile) {
    const nextStatus = profile.status === 'aktif' ? 'nonaktif' : 'aktif';
    if (!window.confirm(`Ubah status akun ${profile.username || profile.name} menjadi ${nextStatus}?`)) return;
    try {
      await api.saveAccount(type, { action: 'set_status', profileId: profile.id, status: nextStatus }, csrfToken);
      await loadItems(user);
      setNotice('Status akun berhasil diperbarui.');
    } catch (requestError) {
      setError(requestError.message);
    }
  }

  function openEdit(item) {
    saveModeRef.current = 'draft';
    setEditing({
      id: item.id,
      proposalNumber: item.proposalNumber,
      title: item.title,
      unitId: item.unitId === null ? '' : String(item.unitId),
      types: String(item.types || '').split(', ').filter(Boolean),
      reason: item.reason || '',
      legalBasis: item.legalBasis || '',
      objective: item.objective || '',
      description: item.description || '',
      status: item.status || 'Draft',
      documents: item.documents || [],
    });
    setShowForm(true);
  }

  async function handleSave(event) {
    event.preventDefault();
    if (!editing) return;

    const formData = new FormData(event.currentTarget);
    const mode = saveModeRef.current;

    if (mode === 'submit' && !window.confirm('Ajukan usulan rancangan peraturan ini untuk ditinjau oleh Biro Hukum?')) {
      return;
    }

    const body = {
      action: editing.id ? 'update' : 'create',
      workflowAction: mode === 'submit' ? 'submit' : 'save',
      id: editing.id,
      proposalNumber: formData.get('proposalNumber') || undefined,
      title: formData.get('title'),
      unitId: formData.get('unitId'),
      types: formData.getAll('types'),
      reason: formData.get('reason'),
      legalBasis: formData.get('legalBasis'),
      objective: formData.get('objective'),
      description: formData.get('description'),
    };
    const files = formData.getAll('documents').filter((file) => file instanceof File && file.size > 0);
    const documentInput = event.currentTarget.elements.namedItem('documents');

    setSaving(true);
    setError('');
    try {
      let saved;
      if (files.length > 0 && mode === 'submit') {
        saved = await api.saveRegulation({ ...body, workflowAction: 'save' }, csrfToken);
        if (!editing.id) {
          setEditing((current) => ({
            ...current,
            id: saved.id,
            proposalNumber: saved.proposalNumber,
            status: saved.status || 'Draft',
          }));
        }
        await api.uploadDocuments(saved.id, files, csrfToken);
        documentInput.value = '';
        await api.saveRegulation({
          ...body,
          action: 'update',
          id: saved.id,
          workflowAction: 'submit',
        }, csrfToken);
      } else {
        saved = await api.saveRegulation(body, csrfToken);
        if (files.length > 0) {
          if (!editing.id) {
            setEditing((current) => ({
              ...current,
              id: saved.id,
              proposalNumber: saved.proposalNumber,
              status: saved.status || 'Draft',
            }));
          }
          await api.uploadDocuments(saved.id, files, csrfToken);
          documentInput.value = '';
        }
      }
      await loadItems(user);
      setShowForm(false);
      setNotice(mode === 'submit'
        ? (editing.status === 'Perlu Revisi' ? 'Revisi berhasil diajukan kembali ke Biro Hukum.' : 'Usulan berhasil diajukan dan masuk ke antrian review.')
        : (editing.status === 'Perlu Revisi' ? 'Perubahan revisi berhasil disimpan dan belum diajukan kembali.' : 'Usulan berhasil disimpan sebagai draft.'));
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(item) {
    if (!window.confirm(`Hapus usulan "${item.title}"?`)) return;
    setError('');
    try {
      const result = await api.saveRegulation({ action: 'delete', id: item.id }, csrfToken);
      await loadItems(user);
      setNotice(result.warning || 'Usulan berhasil dihapus.');
    } catch (requestError) {
      setError(requestError.message);
    }
  }

  async function handleReviewAction(action) {
    if (!activeNotes) return;
    const requiresNote = ['request_revision', 'approve', 'reject'].includes(action);
    if (requiresNote && !noteText.trim()) {
      setError('Tambahkan catatan sebelum mengirim keputusan review.');
      return;
    }
    const confirmation = {
      start_review: 'Mulai review usulan ini?',
      request_revision: 'Kirim usulan kembali kepada pengusul untuk direvisi?',
      approve: 'Setujui usulan ini?',
      reject: 'Tolak usulan ini?',
    }[action];
    if (!window.confirm(confirmation)) return;

    setSaving(true);
    setError('');
    try {
      await api.addNote({ proposalId: activeNotes.id, action, note: noteText }, csrfToken);
      await loadItems(user);
      setActiveNotes(null);
      setNoteText('');
      setNotice('Status usulan berhasil diperbarui.');
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSaving(false);
    }
  }

  useEffect(() => {
    if (!notice) return undefined;
    const timeout = window.setTimeout(() => setNotice(''), 3500);
    return () => window.clearTimeout(timeout);
  }, [notice]);

  if (loading) {
    return <div className="screen-state"><span className="spinner" />Memuat aplikasi...</div>;
  }

  if (!user) {
    return (
      <main className="login-screen">
        <section className="login-card">
          <div className="login-brand">
            <img src="Logo_Komnas_HAM.png" alt="Logo Komnas HAM" />
            <span>RUANG REGULASI</span>
          </div>
          <p className="eyebrow">KOMISI NASIONAL HAK ASASI MANUSIA</p>
          <h1>Selamat datang kembali</h1>
          <p className="muted">Masuk untuk mengelola usulan rancangan peraturan.</p>
          {error && <div className="alert alert-error" role="alert">{error}</div>}
          <form className="login-form" onSubmit={handleLogin}>
            <label htmlFor="username">Username</label>
            <input id="username" name="username" autoComplete="username" required />
            <label htmlFor="password">Password</label>
            <input id="password" name="password" type="password" autoComplete="current-password" required />
            <button className="button button-primary button-wide" type="submit" disabled={saving}>{saving ? 'Memeriksa...' : 'Masuk ke aplikasi'}</button>
          </form>
          <p className="login-footnote">Sistem pemantauan penyusunan peraturan internal</p>
        </section>
      </main>
    );
  }

  const navItems = isAdmin
    ? [
      { label: 'Dashboard', view: 'Dashboard' },
      { label: 'Pengusul', view: 'Pengusul' },
      { label: 'Reviewer Biro Hukum', view: 'Reviewer Biro Hukum' },
      { label: 'Semua Usulan', view: 'Semua Usulan' },
      { label: 'Riwayat', view: 'Riwayat' },
      { label: 'Biro Hukum', view: 'Biro Hukum' },
      { label: 'Jenis Peraturan', view: 'Jenis Peraturan' },
      { label: 'Profil', view: 'Profil' },
    ]
    : isReviewer
      ? [
        { label: 'Dashboard', view: 'Dashboard' },
        { label: 'Usulan Perlu Review', view: 'Usulan Perlu Review' },
        { label: 'Riwayat Review', view: 'Riwayat Review' },
        { label: 'Profil', view: 'Profil' },
      ]
      : [
        { label: 'Dashboard', view: 'Dashboard' },
        { label: 'Usulan Saya', view: 'Usulan Saya' },
        { label: 'Buat Usulan', view: 'create' },
        { label: 'Riwayat', view: 'Riwayat Saya' },
        { label: 'Profil', view: 'Profil' },
      ];
  const pageTitle = activeView === 'Dashboard'
    ? (isAdmin ? 'Dashboard Admin' : isReviewer ? 'Dashboard Reviewer' : 'Dashboard Pengusul')
    : activeView;
  const pageDescription = activeView === 'Dashboard'
    ? (isAdmin ? 'Ringkasan akun dan perkembangan seluruh usulan.' : isReviewer ? 'Tinjau usulan dan ikuti perkembangan review.' : 'Kelola usulan Anda dari draft hingga keputusan akhir.')
    : activeView === 'Pengusul'
      ? 'Kelola profil dan akun pengguna yang mengajukan usulan.'
      : activeView === 'Reviewer Biro Hukum'
        ? 'Kelola profil dan akun reviewer Biro Hukum.'
        : activeView === 'Profil'
          ? 'Informasi profil dan akun yang sedang digunakan.'
          : 'Tinjau data dan perkembangan workflow usulan.';
  const isAccountView = isAdmin && ['Pengusul', 'Reviewer Biro Hukum'].includes(activeView);
  const accountListType = activeView === 'Pengusul' ? 'pengusul' : 'reviewers';
  const accountList = profiles[accountListType];
  const showHistoryView = activeView === 'Riwayat' || activeView === 'Riwayat Saya' || activeView === 'Riwayat Review';
  const reviewHistoryItems = items.flatMap((item) => item.notes.map((note) => ({
    ...note,
    proposalNumber: item.proposalNumber,
    title: item.title,
  }))).sort((a, b) => new Date(b.date) - new Date(a.date));
  const showMasterView = isAdmin && ['Biro Hukum', 'Jenis Peraturan'].includes(activeView);
  const masterDataType = activeView === 'Biro Hukum' ? 'biroHukum' : 'regulationTypes';
  const masterRows = masterData[masterDataType];
  const showProposalList = ['Dashboard', 'Usulan Saya', 'Usulan Perlu Review', 'Semua Usulan'].includes(activeView);
  const navIcons = {
    Dashboard: '▦',
    Pengusul: '♙',
    'Reviewer Biro Hukum': '▤',
    'Semua Usulan': '▧',
    Riwayat: '◷',
    'Biro Hukum': '⌂',
    'Jenis Peraturan': '▣',
    Profil: '♙',
    'Usulan Perlu Review': '⌕',
    'Riwayat Review': '◴',
    'Usulan Saya': '▧',
    'Buat Usulan': '＋',
  };

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <a className="brand" href="./">
          <img src="Logo_Komnas_HAM.png" alt="" />
          <span><strong>RUANG</strong><small>REGULASI</small></span>
        </a>
        <div className="nav-label">MENU UTAMA</div>
        {navItems.map((item) => (
          <button
            key={item.view}
            className={`nav-item ${activeView === item.view ? 'active' : ''}`}
            type="button"
            onClick={() => item.view === 'create' ? openCreate() : setActiveView(item.view)}
        ><span className="nav-icon" aria-hidden="true">{navIcons[item.label] || '•'}</span><span className="nav-text">{item.label}</span><span className="nav-chevron" aria-hidden="true">›</span></button>
        ))}
        <div className="sidebar-bottom">
          <div className="account">
            <div className="avatar">{user.username.slice(0, 1).toUpperCase()}</div>
            <div className="account-copy"><strong>{user.name || user.username}</strong><small>{roleLabels[normalizedRole] || 'PENGGUNA'}</small></div>
          </div>
          <button className="nav-item logout-button" onClick={handleLogout} type="button"><span className="nav-icon">↪</span>Keluar</button>
        </div>
      </aside>

      <main className="main-content" id="dashboard">
        <header className="topbar">
          <div><span className="breadcrumb">Ruang kerja</span><span className="breadcrumb-separator">/</span><strong>{isAdmin ? 'Admin' : isReviewer ? 'Biro Hukum' : 'Pengusul'}</strong></div>
          <span className="role-pill">{roleLabels[normalizedRole] || 'PENGGUNA'}</span>
        </header>

        <section className="page-content">
          <div className="page-heading">
            <div>
              <p className="eyebrow">WORKFLOW USULAN RANCANGAN PERATURAN</p>
              <h1>{pageTitle}</h1>
              <p className="muted">{pageDescription}</p>
            </div>
            {isPengusul && user.proposerId && ['Dashboard', 'Usulan Saya'].includes(activeView) && <button className="button button-primary" onClick={openCreate} type="button"><span>＋</span> Buat Usulan</button>}
          </div>

          {error && <div className="alert alert-error" role="alert">{error}<button onClick={() => setError('')} aria-label="Tutup">×</button></div>}
          {notice && <div className="alert alert-success" role="status">{notice}<button onClick={() => setNotice('')} aria-label="Tutup">×</button></div>}
          {isPengusul && !user.proposerId && <div className="alert alert-error" role="alert">Akun ini belum ditautkan ke profil Pengusul. Hubungi Admin untuk mengaktifkan akses.</div>}
          {isReviewer && !user.reviewerId && <div className="alert alert-error" role="alert">Akun ini belum ditautkan ke profil Reviewer. Hubungi Admin untuk mengaktifkan akses.</div>}

          {activeView === 'Dashboard' && (
            <div className="stat-row">
              {stats.map((card) => (
                <div className="stat-card" key={card.label}>
                  <span className={`stat-icon ${card.tone}`}>{card.icon || '▤'}</span>
                  <div>
                    <span>{card.label}</span>
                    <strong>{card.value}</strong>
                  </div>
                </div>
              ))}
            </div>
          )}

          {showProposalList && <section className="panel">
            <div className="panel-heading">
              <div>
                <h2>{isAdmin ? 'Daftar usulan seluruh unit' : isReviewer ? 'Usulan yang perlu ditindaklanjuti' : 'Usulan Saya'}</h2>
                <p>{isAdmin ? 'Pantau status dan perkembangan setiap usulan.' : isReviewer ? 'Prioritaskan usulan yang menunggu review.' : 'Tinjau progres usulan yang sudah Anda ajukan.'}</p>
              </div>
              <div className={`panel-tools ${isPengusul ? 'panel-tools-filtered' : ''}`}>
                {isPengusul && (
                  <>
                    <select aria-label="Filter status" value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
                      <option value="">Semua status</option>
                      {proposalStatuses.map((status) => <option key={status} value={status}>{status}</option>)}
                    </select>
                    <select aria-label="Filter jenis peraturan" value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)}>
                      <option value="">Semua jenis</option>
                      {lookups.regulationTypes.map((type) => <option key={type.id} value={type.name}>{type.name}</option>)}
                    </select>
                  </>
                )}
                <label className="search-box"><span>⌕</span><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari usulan..." aria-label="Cari usulan" /></label>
              </div>
            </div>

            {filteredItems.length === 0 ? (
              <div className="empty-state"><span>▤</span><strong>{items.length ? 'Usulan tidak ditemukan' : 'Belum ada usulan'}</strong><p>{items.length ? 'Coba ubah kata kunci atau filter.' : 'Usulan yang dibuat akan tampil di sini.'}</p></div>
            ) : (
              <div className={`table-wrap ${isPengusul ? 'proposer-table-wrap' : ''}`}>
                <table>
                  <thead>
                    <tr>
                      <th>NO.</th>
                      {isPengusul ? (
                        <>
                          <th>JUDUL RANCANGAN PERATURAN</th>
                          <th>PENGUSUL</th>
                          <th>JENIS PERATURAN</th>
                          <th>DASAR/ALASAN PENYUSUNAN</th>
                          <th>KETERANGAN</th>
                        </>
                      ) : (
                        <>
                      <th>NOMOR & JUDUL</th>
                      <th>{isAdmin || isReviewer ? 'PENGUSUL / UNIT' : 'JENIS'}</th>
                      <th>STATUS</th>
                        </>
                      )}
                      <th>AKSI</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredItems.map((item, index) => (
                      <tr key={item.id}>
                        <td className="row-number">{String(index + 1).padStart(2, '0')}</td>
                        {isPengusul ? (
                          <>
                            <td className="proposer-table-title">
                              <strong className="regulation-title">{item.title}</strong>
                              <span className="table-note">{item.proposalNumber || `USL-${item.id}`}</span>
                              <span className={`status-badge ${statusMeta[item.status]?.className || 'status-neutral'}`}>{statusMeta[item.status]?.label || item.status || 'Draft'}</span>
                            </td>
                            <td>
                              <span className="table-subtitle">{item.proposerName || user.name || user.username}</span>
                              <span className="table-note">{item.unitName || 'Unit belum ditentukan'}</span>
                            </td>
                            <td>
                              <div className="tag-list">{String(item.types || 'Peraturan').split(', ').filter(Boolean).map((type) => <span className="tag" key={type}>{type}</span>)}</div>
                            </td>
                            <td className="reason-cell">{item.reason || '—'}</td>
                            <td className="reason-cell">{item.description || '—'}</td>
                          </>
                        ) : (
                          <>
                        <td>
                          <strong className="regulation-title">{item.proposalNumber || `USL-${item.id}`}</strong>
                          <span className="table-subtitle">{item.title}</span>
                          <span className="table-note">{[item.proposerName, item.unitName].filter(Boolean).join(' · ') || 'Unit belum ditentukan'}</span>
                        </td>
                        <td>
                          {isAdmin || isReviewer ? (
                            <>
                              <span className="table-subtitle">{item.proposerName || 'Nama pengusul belum diisi'}</span>
                              <span className="table-note">{item.unitName || 'Unit belum diisi'}</span>
                            </>
                          ) : (
                            <div className="tag-list">{String(item.types || 'Peraturan').split(', ').filter(Boolean).map((type) => <span className="tag" key={type}>{type}</span>)}</div>
                          )}
                        </td>
                        <td>
                          <span className={`status-badge ${statusMeta[item.status]?.className || 'status-neutral'}`}>{statusMeta[item.status]?.label || item.status}</span>
                        </td>
                          </>
                        )}
                        <td>
                          <div className="actions">
                            {isPengusul && ['Draft', 'Perlu Revisi'].includes(item.status) && <button type="button" className="icon-button" aria-label={item.status === 'Draft' ? 'Edit usulan' : 'Perbaiki usulan'} title={item.status === 'Draft' ? 'Edit usulan' : 'Perbaiki usulan'} onClick={() => openEdit(item)}>{item.status === 'Draft' ? '✎' : '↻'}</button>}
                            {isPengusul && item.status === 'Draft' && <button type="button" className="icon-button danger" aria-label="Hapus usulan draft" title="Hapus usulan draft" onClick={() => handleDelete(item)}>⌫</button>}
                            {isReviewer && <button type="button" className="icon-button" onClick={() => { setActiveNotes(item); setNoteText(''); }}>{['Menunggu Review', 'Diajukan Kembali'].includes(item.status) ? 'Mulai' : 'Lihat'}</button>}
                            {isPengusul && <button type="button" className="icon-button proposal-detail-button" onClick={() => { setActiveNotes(item); setNoteText(''); }}>{item.status === 'Disetujui' ? 'Detail' : 'Lihat'}</button>}
                            {isAdmin && <button type="button" className="icon-button" onClick={() => { setActiveNotes(item); setNoteText(''); }}>Lihat</button>}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <div className="panel-footer">Menampilkan {filteredItems.length} dari {items.length} usulan</div>
          </section>}

          {isAccountView && (
            <section className="panel">
              <div className="panel-heading">
                <div>
                  <h2>{activeView}</h2>
                  <p>{activeView === 'Pengusul' ? 'Akun dibuat oleh Admin dan ditautkan ke profil Pengusul.' : 'Akun reviewer yang terhubung dengan unit Biro Hukum.'}</p>
                </div>
                <button className="button button-primary" type="button" onClick={() => openAccountCreate(accountListType)}>
                  <span>＋</span> Tambah {activeView === 'Pengusul' ? 'Pengusul' : 'Reviewer'}
                </button>
              </div>
              {accountList.length === 0 ? (
                <div className="empty-state"><span>◎</span><strong>Belum ada profil</strong><p>Akun baru akan tampil di sini setelah dibuat.</p></div>
              ) : (
                <div className="table-wrap">
                  <table>
                    <thead><tr><th>NAMA</th><th>{accountListType === 'pengusul' ? 'BIRO / UNIT' : 'BIRO HUKUM'}</th><th>JABATAN</th><th>USERNAME</th><th>STATUS</th><th>AKSI</th></tr></thead>
                    <tbody>
                      {accountList.map((profile) => (
                        <tr key={profile.id}>
                          <td><strong className="regulation-title">{profile.name}</strong><span className="table-subtitle">{profile.email || 'Email belum diisi'}</span></td>
                          <td>{profile.unitName || profile.bureauName || '—'}</td>
                          <td>{profile.position || '—'}</td>
                          <td>{profile.username || 'Akun belum terhubung'}</td>
                          <td><span className={`status-badge ${profile.status === 'aktif' ? 'status-success' : 'status-neutral'}`}>{profile.status}</span></td>
                          <td><button className="icon-button" type="button" onClick={() => handleAccountStatus(accountListType, profile)}>{profile.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'}</button></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              <div className="panel-footer">Total {accountList.length} profil</div>
            </section>
          )}

          {showHistoryView && (
            <section className="panel">
              <div className="panel-heading"><div><h2>{activeView}</h2><p>Jejak perubahan status dan catatan review pada workflow baru.</p></div></div>
              {activeView === 'Riwayat Review' ? (
                reviewHistoryItems.length === 0 ? <div className="empty-state"><span>◷</span><strong>Belum ada riwayat review</strong><p>Aktivitas reviewer akan tercatat di sini.</p></div> : (
                  <div className="table-wrap"><table><thead><tr><th>TANGGAL</th><th>USULAN</th><th>REVIEWER</th><th>PUTARAN</th><th>KEPUTUSAN</th><th>CATATAN</th></tr></thead><tbody>
                    {reviewHistoryItems.map((entry, index) => <tr key={`${entry.proposalNumber}-${entry.reviewRound}-${index}`}><td>{entry.date}</td><td><strong className="regulation-title">{entry.proposalNumber}</strong><span className="table-subtitle">{entry.title}</span></td><td>{entry.reviewerName || 'Reviewer'}</td><td>{entry.reviewRound || '—'}</td><td>{entry.decision || '—'}</td><td>{entry.text || '—'}</td></tr>)}
                  </tbody></table></div>
                )
              ) : (
                historyItems.length === 0 ? <div className="empty-state"><span>◷</span><strong>Belum ada riwayat status</strong><p>Perubahan status akan tercatat di sini.</p></div> : (
                  <div className="table-wrap"><table><thead><tr><th>TANGGAL</th><th>USULAN</th><th>PERUBAHAN</th><th>AKTOR</th><th>CATATAN</th></tr></thead><tbody>
                    {historyItems.map((entry, index) => <tr key={`${entry.proposalNumber}-${entry.date}-${index}`}><td>{entry.date}</td><td><strong className="regulation-title">{entry.proposalNumber}</strong><span className="table-subtitle">{entry.title}</span></td><td>{entry.oldStatus || 'Usulan baru'} → {entry.newStatus}</td><td>{entry.actorName}</td><td>{entry.notes || '—'}</td></tr>)}
                  </tbody></table></div>
                )
              )}
              <div className="panel-footer">Riwayat berasal dari aktivitas yang dicatat dalam workflow baru.</div>
            </section>
          )}

          {showMasterView && (
            <section className="panel">
              <div className="panel-heading">
                <div><h2>{activeView}</h2><p>Master data aktif yang digunakan oleh akun dan formulir usulan.</p></div>
                <button className="button button-primary" type="button" onClick={() => openMasterCreate(activeView === 'Biro Hukum' ? 'biro-hukum' : 'jenis-peraturan')}><span>＋</span> Tambah {activeView}</button>
              </div>
              <div className="table-wrap"><table><thead><tr><th>NAMA</th><th>KODE / ID</th><th>STATUS</th><th>AKSI</th></tr></thead><tbody>
                {masterRows.map((entry) => <tr key={entry.id}><td>{entry.name}</td><td>{entry.code || entry.id}</td><td><span className={`status-badge ${entry.status === 'aktif' ? 'status-success' : 'status-neutral'}`}>{entry.status}</span></td><td><button className="icon-button" type="button" onClick={() => handleMasterStatus(entry)}>{entry.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'}</button></td></tr>)}
              </tbody></table></div>
              <div className="panel-footer">Total {masterRows.length} data</div>
            </section>
          )}

          {activeView === 'Profil' && (
            <>
              <section className="panel profile-panel">
                <div className="panel-heading"><div><h2>Profil Akun</h2><p>Identitas yang diperoleh dari sesi login.</p></div></div>
                <dl className="profile-details">
                  <div><dt>Nama lengkap</dt><dd>{user.name || '—'}</dd></div>
                  <div><dt>Username</dt><dd>{user.username}</dd></div>
                  <div><dt>Peran</dt><dd>{roleLabels[normalizedRole] || normalizedRole}</dd></div>
                  <div><dt>Biro / Unit</dt><dd>{user.unitName || '—'}</dd></div>
                  <div><dt>Jabatan</dt><dd>{user.position || '—'}</dd></div>
                </dl>
              </section>
              <section className="panel password-panel">
                <div className="panel-heading">
                  <div><h2>Ganti Password</h2><p>Gunakan minimal 12 karakter untuk password baru.</p></div>
                </div>
                <form className="password-form" onSubmit={handleChangePassword}>
                  <label className="field-label" htmlFor="current-password">Password Saat Ini</label>
                  <input id="current-password" name="currentPassword" type="password" autoComplete="current-password" maxLength="4096" required />
                  <label className="field-label" htmlFor="new-password">Password Baru</label>
                  <input id="new-password" name="newPassword" type="password" autoComplete="new-password" minLength="12" maxLength="4096" required />
                  <label className="field-label" htmlFor="confirm-password">Konfirmasi Password Baru</label>
                  <input id="confirm-password" name="confirmPassword" type="password" autoComplete="new-password" maxLength="4096" required />
                  <div className="password-actions">
                    <button className="button button-primary" type="submit" disabled={saving}>{saving ? 'Menyimpan...' : 'Simpan Password'}</button>
                  </div>
                </form>
              </section>
            </>
          )}

          <footer className="page-footer">Komisi Nasional Hak Asasi Manusia <span>•</span> Ruang Regulasi</footer>
        </section>
      </main>

      {showForm && editing && (
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setShowForm(false); }}>
          <section className="modal-card" role="dialog" aria-modal="true" aria-labelledby="form-title">
            <div className="modal-heading">
              <div>
                <p className="eyebrow">AKSI USULAN</p>
                <h2 id="form-title">{editing.id ? 'Edit usulan' : 'Ajukan Usulan Rancangan Peraturan'}</h2>
              </div>
              <button className="close-button" onClick={() => setShowForm(false)} aria-label="Tutup">×</button>
            </div>
            <form onSubmit={handleSave} onInvalidCapture={() => { saveModeRef.current = 'draft'; }}>
              <label className="field-label" htmlFor="proposalNumber">Nomor usulan</label>
              <input id="proposalNumber" name="proposalNumber" value={editing.proposalNumber || ''} onChange={(event) => setEditing((current) => ({ ...current, proposalNumber: event.target.value }))} placeholder="Otomatis dibuat sistem" />

              <label className="field-label" htmlFor="form-title-input">Judul rancangan peraturan <em>*</em></label>
              <input id="form-title-input" name="title" defaultValue={editing.title} required maxLength="255" />

              <div className="identity-summary">
                <strong>Identitas Pengusul</strong>
                <span>{user.name || user.username}</span>
                <small>{user.unitName || 'Unit belum ditentukan'}{user.position ? ` · ${user.position}` : ''}</small>
              </div>

              <label className="field-label" htmlFor="unitId">Biro / Unit usulan</label>
              <select id="unitId" name="unitId" defaultValue={editing.unitId || user.unitId || ''}>
                <option value="">Gunakan unit profil</option>
                {lookups.units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
              </select>

              <fieldset className="type-field">
                <legend>Jenis peraturan <em>*</em></legend>
                <div className="checkbox-row">
                  {lookups.regulationTypes.map((type) => (
                    <label key={type.id} className="check-option"><input name="types" type="checkbox" value={type.name} defaultChecked={editing.types.includes(type.name)} />{type.name}</label>
                  ))}
                </div>
              </fieldset>

              <label className="field-label" htmlFor="reason">Alasan pengusulan <em>*</em></label>
              <textarea id="reason" name="reason" rows="4" defaultValue={editing.reason} required />

              <label className="field-label" htmlFor="legalBasis">Dasar hukum</label>
              <textarea id="legalBasis" name="legalBasis" rows="2" defaultValue={editing.legalBasis} />

              <label className="field-label" htmlFor="objective">Tujuan</label>
              <textarea id="objective" name="objective" rows="2" defaultValue={editing.objective} />

              <label className="field-label" htmlFor="description">Deskripsi tambahan</label>
              <textarea id="description" name="description" rows="3" defaultValue={editing.description} />

              <label className="field-label" htmlFor="proposal-documents">Dokumen pendukung</label>
              <input id="proposal-documents" name="documents" type="file" accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" multiple />
              <p className="form-hint">PDF atau DOCX, maksimal 10 MB per file dan 5 file per pengiriman.</p>
              {editing.documents.length > 0 && (
                <div className="document-list">
                  <strong>Dokumen terlampir</strong>
                  {editing.documents.map((document) => (
                    <a key={document.id} href={api.documentUrl(document.id)}>{document.name}</a>
                  ))}
                </div>
              )}

              <div className="modal-actions">
                <button type="button" className="button button-light" onClick={() => setShowForm(false)}>Batal</button>
                <button type="submit" className="button button-primary" onClick={() => { saveModeRef.current = 'draft'; }} disabled={saving}>{saving ? 'Menyimpan...' : editing.status === 'Perlu Revisi' ? 'Simpan Perubahan' : 'Simpan sebagai Draft'}</button>
                <button type="submit" className="button button-primary" onClick={() => { saveModeRef.current = 'submit'; }} disabled={saving}>{saving ? 'Mengajukan...' : editing.status === 'Perlu Revisi' ? 'Ajukan Kembali' : 'Ajukan Usulan'}</button>
              </div>
            </form>
          </section>
        </div>
      )}

      {showAccountForm && isAdmin && (
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setShowAccountForm(false); }}>
          <section className="modal-card" role="dialog" aria-modal="true" aria-labelledby="account-form-title">
            <div className="modal-heading">
              <div>
                <p className="eyebrow">ADMINISTRASI AKUN</p>
                <h2 id="account-form-title">Tambah {accountType === 'pengusul' ? 'Pengusul' : 'Reviewer Biro Hukum'}</h2>
                <p className="muted">Profil dan akun login dibuat bersamaan.</p>
              </div>
              <button className="close-button" onClick={() => setShowAccountForm(false)} aria-label="Tutup">×</button>
            </div>
            <form className="account-form" onSubmit={handleAccountSave}>
              <label className="field-label" htmlFor="account-name">Nama lengkap <em>*</em></label>
              <input id="account-name" name="name" required maxLength="255" />

              {accountType === 'pengusul' ? (
                <>
                  <label className="field-label" htmlFor="account-unit">Biro / Unit <em>*</em></label>
                  <select id="account-unit" name="unitId" required defaultValue="">
                    <option value="" disabled>Pilih unit</option>
                    {lookups.units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
                  </select>
                </>
              ) : (
                <>
                  <label className="field-label" htmlFor="account-bureau">Biro Hukum <em>*</em></label>
                  <select id="account-bureau" name="bureauId" required defaultValue="">
                    <option value="" disabled>Pilih Biro Hukum</option>
                    {lookups.biroHukum.map((bureau) => <option key={bureau.id} value={bureau.id}>{bureau.name}</option>)}
                  </select>
                </>
              )}

              <div className="field-grid">
                <div><label className="field-label" htmlFor="account-position">Jabatan</label><input id="account-position" name="position" maxLength="255" /></div>
                <div><label className="field-label" htmlFor="account-status">Status</label><select id="account-status" name="status" defaultValue="aktif"><option value="aktif">Aktif</option><option value="nonaktif">Nonaktif</option></select></div>
              </div>
              <div className="field-grid">
                <div><label className="field-label" htmlFor="account-email">Email</label><input id="account-email" name="email" type="email" maxLength="255" /></div>
                <div><label className="field-label" htmlFor="account-phone">No. HP</label><input id="account-phone" name="phone" maxLength="40" /></div>
              </div>

              <label className="field-label" htmlFor="account-username">Username <em>*</em></label>
              <input id="account-username" name="username" required maxLength="100" autoComplete="off" />
              <label className="field-label" htmlFor="account-password">Password awal <em>*</em></label>
              <input id="account-password" name="password" type="password" required minLength="12" autoComplete="new-password" />
              <p className="form-hint">Gunakan minimal 12 karakter. Akun nonaktif tidak dapat login.</p>
              <div className="modal-actions">
                <button type="button" className="button button-light" onClick={() => setShowAccountForm(false)}>Batal</button>
                <button type="submit" className="button button-primary" disabled={saving}>{saving ? 'Menyimpan...' : 'Buat Akun'}</button>
              </div>
            </form>
          </section>
        </div>
      )}

      {showMasterForm && isAdmin && (
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setShowMasterForm(false); }}>
          <section className="modal-card" role="dialog" aria-modal="true" aria-labelledby="master-form-title">
            <div className="modal-heading">
              <div><p className="eyebrow">MASTER DATA</p><h2 id="master-form-title">Tambah {masterType === 'biro-hukum' ? 'Biro Hukum' : 'Jenis Peraturan'}</h2></div>
              <button className="close-button" onClick={() => setShowMasterForm(false)} aria-label="Tutup">×</button>
            </div>
            <form className="account-form" onSubmit={handleMasterSave}>
              <label className="field-label" htmlFor="master-name">Nama <em>*</em></label>
              <input id="master-name" name="name" required maxLength="255" />
              {masterType === 'biro-hukum' ? (
                <><label className="field-label" htmlFor="master-code">Kode <em>*</em></label><input id="master-code" name="code" required maxLength="40" /></>
              ) : (
                <><label className="field-label" htmlFor="master-description">Deskripsi</label><textarea id="master-description" name="description" rows="3" /></>
              )}
              <div className="modal-actions">
                <button type="button" className="button button-light" onClick={() => setShowMasterForm(false)}>Batal</button>
                <button type="submit" className="button button-primary" disabled={saving}>{saving ? 'Menyimpan...' : 'Simpan'}</button>
              </div>
            </form>
          </section>
        </div>
      )}

      {activeNotes && (
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setActiveNotes(null); }}>
          <section className="modal-card notes-modal" role="dialog" aria-modal="true" aria-labelledby="notes-title">
            <div className="modal-heading">
              <div>
                <p className="eyebrow">{isPengusul ? 'DETAIL USULAN' : 'RIWAYAT REVIEW'}</p>
                <h2 id="notes-title">{isPengusul ? 'Detail Usulan' : 'Detail usulan'}</h2>
                <p className="muted modal-subtitle">{activeNotes.title}</p>
              </div>
              <button className="close-button" onClick={() => setActiveNotes(null)} aria-label="Tutup">×</button>
            </div>
            {error && <div className="alert alert-error" role="alert">{error}</div>}
            {isPengusul && (
              <div className="proposal-detail-list">
                {activeNotes.status === 'Disetujui' && (
                  <div className="alert alert-success" role="status">Usulan ini telah disetujui oleh Reviewer.</div>
                )}
                <div><strong>Status</strong><span className={`status-badge ${statusMeta[activeNotes.status]?.className || 'status-neutral'}`}>{statusMeta[activeNotes.status]?.label || activeNotes.status || 'Draft'}</span></div>
                <div><strong>Nomor usulan</strong><span>{activeNotes.proposalNumber || `USL-${activeNotes.id}`}</span></div>
                <div><strong>Jenis peraturan</strong><span>{activeNotes.types || '—'}</span></div>
                <div><strong>Pengusul / Unit</strong><span>{[activeNotes.proposerName || user.name, activeNotes.unitName].filter(Boolean).join(' · ') || '—'}</span></div>
                <div><strong>Dasar / Alasan penyusunan</strong><span>{activeNotes.reason || '—'}</span></div>
                <div><strong>Keterangan</strong><span>{activeNotes.description || '—'}</span></div>
              </div>
            )}
            {isPengusul && <h3 className="proposal-notes-heading">Catatan Reviewer</h3>}
            <div className="notes-list">
              {(activeNotes.notes && activeNotes.notes.length ? activeNotes.notes : [{ text: 'Belum ada catatan untuk usulan ini.' }]).map((note, index) => (
                <article className="note-entry" key={`${note.text}-${index}`}>
                  <span className="note-dot" />
                  <div>
                    <p>{note.text}</p>
                    {note.decision && <small>{note.decision} · Putaran {note.reviewRound} · {note.reviewerName || 'Reviewer'}</small>}
                    {note.date && <small>{note.date}</small>}
                  </div>
                </article>
              ))}
            </div>
            <div className="document-list">
              <strong>Dokumen pendukung</strong>
              {activeNotes.documents?.length ? activeNotes.documents.map((document) => (
                <a key={document.id} href={api.documentUrl(document.id)}>{document.name}</a>
              )) : <span className="muted">Belum ada dokumen.</span>}
            </div>
            {isReviewer && (
              <div className="add-note-form">
                {['Menunggu Review', 'Diajukan Kembali'].includes(activeNotes.status) && (
                  <button className="button button-primary" type="button" onClick={() => handleReviewAction('start_review')} disabled={saving}>{saving ? 'Memproses...' : 'Mulai Review'}</button>
                )}
                {activeNotes.status === 'Sedang Direview' && activeNotes.notes.at(-1)?.reviewerId === user.reviewerId && (
                  <>
                    <label className="field-label" htmlFor="new-note">Catatan review</label>
                    <textarea id="new-note" rows="4" value={noteText} onChange={(event) => setNoteText(event.target.value)} maxLength="10000" placeholder="Catatan wajib untuk keputusan atau permintaan revisi." />
                    <div className="modal-actions">
                      <button className="button button-light" type="button" onClick={() => handleReviewAction('request_revision')} disabled={saving || !noteText.trim()}>Minta Revisi</button>
                      <button className="button button-primary" type="button" onClick={() => handleReviewAction('approve')} disabled={saving || !noteText.trim()}>Setujui</button>
                      <button className="button button-light" type="button" onClick={() => handleReviewAction('reject')} disabled={saving || !noteText.trim()}>Tolak</button>
                    </div>
                  </>
                )}
                {activeNotes.status === 'Sedang Direview' && activeNotes.notes.at(-1)?.reviewerId !== user.reviewerId && <p className="muted">Review ini dimulai oleh reviewer lain dan hanya dapat diselesaikan oleh reviewer tersebut.</p>}
              </div>
            )}
          </section>
        </div>
      )}
    </div>
  );
}

export default App;
