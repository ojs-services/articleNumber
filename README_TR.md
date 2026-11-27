# Article Number Plugin for OJS

[English](README.md)

**OJS 3.4.0.x ve 3.5.0.x** için genel (generic) eklenti; iki sürüm için tek paket. OJS 3.3'ün kendi paketi vardır (1.x).

Article Number (Makale Numarası), bir makaleye sayfa aralığından ayrı, kendi üst verisi olan dijital bir konum bilgisi verir (*elocation-id*, örneğin `e0231470` veya `P2.16`). Crossref, JATS, Google Scholar ve atıf çıktısı bunu uydurma bir sayfa aralığı yerine makale numarası olarak alır.

Sürekli yayın yapan dergilerde sayfa numarası yoktur. Birçoğu makale numarasını *Sayfalar* alanına yazar; Google Scholar bu durumda ilk sayfası son sayfasına eşit sahte bir kayıt dizinler, Crossref ve PMC de var olmayan bir sayfa aralığı alır. Eklenti bu boşluğu doldurur ve `pages` alanına hiçbir zaman yazmaz.

## Ne yapar

| Alan | Davranış |
|---|---|
| Editör alanı | Yayın **Sayı** formunda, *Sayfalar*'dan sonra, kullanım yönergesiyle bir *Makale Numarası* alanı. |
| Benzersizlik | Aynı numara bir dergide (ya da ayara göre bir sayıda) iki makaleye kaydedilemez. Denetim, yayımlanmamış makalede kayıtta engeller. Eklentinin kendisi de kullanılan bir numarayı yazmaz (yayımda da göçte de). |
| QuickSubmit | Aynı alan QuickSubmit eklentisinin formunda da vardır; aynı yinelenen numara denetimiyle. |
| Yayımlanmış makale | Makale yayımlandıktan sonra numarası kilitlenir; yatırılmış bir konum bilgisi yanlışlıkla değişmez. |
| Makale sayfası | Makale ayrıntılarında *Makale Numarası: X* gösterir; tema numarayı kendisi basıyorsa dergi bazında gizlenebilir. |
| Sayı içindekiler | Her makalenin satırında, normalde sayfa numarasının çıkacağı yerde numarayı gösterir. |
| Google Scholar | Numarası olan makalede `citation_firstpage` makale numarasını taşır (PLOS'un yaptığı gibi) ve `citation_lastpage` gönderilmez; *Sayfalar*'dan üretilen sahte ilk = son sayfa çifti gitmez. Sürüm URL'lerinde (`article/view/ID/version/N`) hiçbir şey eklenmez; OJS çekirdeği de orada Scholar etiketi basmaz. Numarasız makale etkilenmez. |
| Crossref | `<publisher_item><item_number item_number_type="article_number">` yazar, `<pages>` bırakılır. |
| JATS | JATS eklentisi (jatsTemplate) kuruluysa `<fpage>` / `<lpage>` yerine `<elocation-id>` yazar. |
| Atıflar | Numara, paketle gelen stillerin atfında görünür (APA: *Article e0231470*). *Bilinen sınırlar*'a bakın. |
| Göç | *Sayfalar*'a yazılmış makale numaralarını yeni alana kopyalar: eklentinin ayar penceresinden (tek dergi) ya da komut satırından (bir ya da tüm dergiler). Kopyalar; `pages` değişmez, kopya geri alınabilir. |
| Numara önerisi | İsteğe bağlı, varsayılan kapalı: yeni makale için sıradaki numarayı önerir. |
| Diller | İngilizce, Türkçe, İspanyolca, Portekizce (Portekiz ve Brezilya). Başka bir arayüz dilinde eklenti metinleri İngilizce görünür. |

Özellik her dergide **varsayılan olarak kapalıdır**. Dergi yöneticisi açana kadar hiçbir şey değişmez.

## Gereksinimler

- OJS 3.4.0.x veya 3.5.0.x. OJS 3.4.0-10 (PHP 8.1) ve 3.5.0-5 (PHP 8.2) üzerinde sınandı.
- `config.inc.php` değişikliği yok, veritabanı tablosu yok. Değer, yayın ayarlarında `workNumber` adıyla saklanır.
- QuickSubmit desteği için eklenti galerisindeki ayrı *QuickSubmit* eklentisi gerekir.
- JATS çıktısı için *JATS Template* (ve *OAI JATS*) eklentisi kurulu olmalıdır; OJS 3.5'te hazır gelir, OJS 3.4'te eklenti galerisinden kurulur.

## Kurulum

Yönetici olarak oturum açın, *Website → Plugins → Upload a new plugin* ile `.tar.gz` paketini yükleyin ve genel eklentiler listesinde **Article Number Plugin**'i etkinleştirin. Ardından eklentinin *Settings* bağlantısını açıp *Enable the Article Number field for this journal* kutusunu işaretleyin.

## Ayarlar

- **Makale Numarası alanını etkinleştir** — dergi bazında, varsayılan kapalı.
- **Makale ayrıntı bloğunda göster** — varsayılan açık; etkin tema numarayı kendisi basıyorsa (Atlas, Nivo, Axis, JournalPlus ya da bunu bildiren bir tema) kapalı başlar, böylece numara iki kez görünmez; ayar penceresi bunu belirtir. Siz açar ya da kapatırsanız seçiminiz korunur.
- **Sayı içindekilerde göster** (varsayılan açık) — aynısı, sayı içindekiler sayfası için.
- **Makale yayımlanırken numarayı sayfa değerinden türet** (varsayılan açık) — bir makale Makale Numarası olmadan yayımlandığında ya da zamanlandığında ve *Sayfalar* değeri bir makale numarasına benziyorsa (Tarama ile aynı kural: örneğin `e0231470`), değer alana kopyalanır. Gerçek sayfa aralıklarına ve belirsiz değerlere dokunulmaz, elle girilmiş numara hiçbir zaman ezilmez, *Sayfalar* değişmez. Başka bir makalenin kullandığı numara (aşağıdaki benzersizlik ayarına göre) **yazılmaz**: yayımlamadan önce yayım penceresinde ve *Makale Numarası* alanının altında uyarı çıkar, yayımlayan kişinin açtığı bir sonraki sayfada bir bildirim de görünür (yayım ekranının kendisinde değil), Tarama makaleyi elle kontrol listesine alır. Yayımlama engellenmez. Her numarayı kendiniz yazmak için buradan kapatın.
- **Benzersizlik** — *tüm dergi* (varsayılan) ya da *sayı bazında*.
- **Numara önerisi** (isteğe bağlı) — `e{:04d}` gibi bir kalıp (`e0001`, `e0002`, …). Öneri alanı hiçbir zaman sınırlamaz: istenen değer yazılabilir. Kullanımda olan numara önerilmez.

## Kullanım

1. Bir makalede *Publication → Issue* sekmesinde *Article Number* alanına numarayı yazın. *Pages* alanını boş bırakın: numara varken makalenin konum bilgisi odur. İkisi de doluysa Crossref, JATS ve Google Scholar Makale Numarasını kullanır; DOAJ, PubMed ve diğer dışa aktarımlar Pages alanını kullanır.
2. Yayımdan sonra numara kilitlenir. Zaten yayımlanmış makaleler için formu değil, aşağıdaki göçü kullanın.

## Göç

Numarayı *Sayfalar*'a yazmış dergiler içindir. Eklenti `e0231470`, `e123`, `P2.16`, `ID45` ve uzun sıfır dolgulu numaraları tanır; gerçek sayfa aralıklarına (`245-260` ve `I-VIII` gibi Roma rakamlı ön kısım) dokunulmaz; belirsiz değerler (örneğin `e-762`, `e714-0`, `Editorial`) yalnızca elle bakılmak üzere, her biri ayrı satırda listelenir; araç bunları hiçbir zaman yeniden yazmaz. Başka bir makalede zaten bulunan ya da iki makaleye birden düşecek bir değer için de aynısı geçerlidir (benzersizlik ayarına göre): hiçbiri yazılmaz, her biri öbür gönderinin numarasıyla listelenir, karar sizindir.

- **Ayar penceresi:** *Scan* neyin dönüştürüleceğini gösterir, *Apply* kopyalar, *Undo* aracın kopyaladıklarını kaldırır (elle girilen numaralar kalır). Elle kontrol gerektiren değerler sonucun en üstünde tam liste olarak görünür (yayın no, sayı, makale başlığı, *Sayfalar* değeri ve nedeni); ardından sayımlar gelir: dergideki makaleler (yayımlanmış ve diğer), toplam yayın sürümü ve dönüştürülecekler. Yalnız her makalenin güncel sürümü dönüştürülür. Yalnız yayımlanmış ve zamanlanmış makaleler dönüştürülür; devam eden ya da reddedilen makaleler atlanır ve öyle gösterilir (makale yayımlandığında *Sayfalar* değeri başka makalenin kullanmadığı bir makale numarasına benziyorsa numara kendiliğinden yazılır; değilse numarayı yayımdan önce Sayı formuna yazın). *Tam sonucu indir (CSV)* makale başına bir satır verir: dönüştürülecek, elle bakılacak, atlanan (gerçek sayfa aralığı, zaten numaralı, sayfa yok).
- **Komut satırı:**

```
php plugins/generic/articleNumber/tools/migrateArticleNumbers.php <dry-run|apply|rollback> [dergiYolu|all]
```

Bir sınırın (varsayılan 2000 makale) üstünde pencere *Apply*'ı kilitler ve onun yerine komut satırını gösterir.

Siz çalıştırmadıkça hiçbir şey taşınmaz. Göçten sonra, Crossref bu makaleler için bir sonraki yatırışta `pages` yerine `item_number` alır; Crossref'te kayıtlı olanlar o zamana kadar değişmez. Eklenti DOI'ye ve DOI sonekine hiçbir zaman bakmaz, değiştirmez.

## Temalar

Kutudan çıktığı gibi numara her temada makale ayrıntılarında bir öğe olarak görünür. Numarayı kendi yerinde basmak isteyen tema `{article_number}` yardımcısını kullanabilir; bkz. [docs/THEME-INTEGRATION.md](docs/THEME-INTEGRATION.md). Tema numarayı zaten gösteriyorsa yukarıdaki iki gösterim ayarını kapatın. Tema bunu kendisi de bildirebilir; temanın yanıtı eklentinin yerleşik listesinden önce gelir.

## Bilinen sınırlar

- **IEEE:** *Art. no.* etiketi yalnızca makale sayfasındaki ana atıfa uygulanır. Stil değiştirici ve indirilen atıf numarayı *p.* etiketiyle gösterir.
- **ABNT, Harvard, IEEE:** bu stiller her konum bilgisini *p.* diye etiketler; numara doğru ama bu etiketle görünür. Paketle gelen stil dosyalarına dokunulmaz.
- **RIS ve BibTeX indirmeleri** numarayı sayfa alanında taşır (`SP`, `pages`).
- **Native XML dışa ve içe aktarım** numarayı taşımaz. Başka bir dergiye aktarılan makale numarasız gelir (*Sayfalar* değeri gelir).
- **DOAJ, PubMed ve TR Dizin dışa aktarımları** numarayı değil *Sayfalar* alanını kullanır. **Dublin Core ve DataCite**'te burada makale numarası alanı yoktur; `pages` değerine dokunulmaz.
- **OAI-PMH:** numara yalnızca JATS'ta (`elocation-id`) çıkar. `oai_dc` yalnızca *Sayfalar*'ı taşır; MARC biçimleri ikisini de taşımaz.
- **Numarayı kendisi basan temalarda** (örneğin Nivo, Axis) makale sayfasındaki kartı kapatın (*Makale ayrıntı bloğunda göster*), yoksa numara iki kez görünür.
- **Crossref:** bir `item_number` en çok 32 karakter olabilir; daha uzun bir numara Crossref dosyasını geçersiz kılar.
- **Numara önerisi:** alanın altında yardım metni olarak gösterilir; sizin için doldurulmaz.
- **QuickSubmit** numara önerisi göstermez: öneri yalnızca makalenin *Publication → Issue* formunda çıkar, QuickSubmit ise makaleyi tek adımda yayımlar.

## Lisans ve destek

GNU GPL v3.

**OJS Services** — [ojs-services.com](https://ojs-services.com) · info@ojs-services.com
