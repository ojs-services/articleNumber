# Article Number Plugin for OJS

[English](README.md)

**OJS 3.3** için genel (generic) bir eklenti. Dijital öncelikli bir **Makale Numarası**'nı
(*elocation-id*) **sayfa aralığı alanından ayrı** bir metaveri olarak yönetir; dijital
öncelikli dergilerin makaleleri gerçekte tanımladığı biçimde (ör. *PLoS ONE 15(4):
e0231470*, *Electron. J. Combin. 27 (2020) P2.16*).

OJS'te bunun için ayrı bir alan yoktur; editörler değeri `pages` alanına sıkıştırır.
Bu, aşağı akıştaki metaveriyi bozar: Google Scholar sahte bir `firstpage = lastpage`
indeksler, Crossref ayrı bir `<item_number>` bekler, PMC/PubMed `<elocation-id>`
ister. Bu eklenti bu boşluğu uçtan uca doldurur ve **çekirdek veriye hiç dokunmaz**;
özellikle **`pages` alanına asla yazmaz**.

**Gereksinimler:** OJS 3.3.x · PHP 7.4–8.1 · MySQL/MariaDB. Tek ve çok dergili
kurulumlarda, varsayılan ve özel temalarda çalışır. OMP/OPS kapsam dışıdır. OJS 3.4 ve
3.5'in kendi paketi vardır (2.x).

## Tasarım ilkeleri

- **Veri adı = `workNumber`.** Değer, PKP'nin iç çekirdek özellik adı `workNumber`
  altında `publication_settings` tablosunda saklanır (sürümlü, yayın başına).
  Kullanıcıya görünen etiket **"Makale Numarası"**dır. Bu uyum sayesinde OJS çekirdeği
  bir gün özelliği kendisi getirirse devir sıfır göçle yapılabilir.
- **Çekirdek salt okunur.** Eklenti yalnızca kendi `workNumber` değerini yazar.
  `pages` veya başka bir çekirdek alana dokunmaz.
- **Dergi başına isteğe bağlı.** Özellik varsayılan olarak KAPALIDIR; her dergi ayrı
  açar. Kapalıyken OJS davranışı değişmez.
- **Çatal değil, ekleme.** Dışa aktarım entegrasyonu (Crossref / JATS / Scholar)
  mevcut çıktıya eklenir; dışa aktarım eklentileri çatallanmaz.
- **Karşılıklı dışlama bir dışa aktarım kuralıdır.** Makale numarası varken sayfa
  aralığı dışa aktarım çıktısında bastırılır; ikisi birlikte gönderilmez.

## Ne yapar

| Alan | Davranış |
|---|---|
| Editör arayüzü | Yayın sayı-girişi formunda (*Sayfalar*'dan sonra) kullanım yönergeli "Makale Numarası" alanı; yayımlanmış koordinatın değiştirilmesi engellenir. |
| Quick Submit | Aynı alan Quick Submit içe aktarma formunda da vardır. |
| Benzersizlik | Aynı Makale Numarası bir dergide iki makaleye verilemez; yayımlanmamış makalede kayıtta engellenir. Kapsam dergi ayarıdır (varsayılan tüm dergi; isteğe bağlı sayı başına). |
| Okur sayfası | Makale sayfasında "Makale Numarası: X" gösterir (temadan bağımsız); tema kendisi gösteriyorsa dergi bazında gizlenebilir. |
| Google Scholar | Makale numarası varsa `citation_firstpage` numarayı taşır (PLOS'un yaptığı gibi) ve `citation_lastpage` gönderilmez; *Sayfalar*'dan üretilen sahte ilk = son sayfa çifti gönderilmez. |
| Crossref | `<publisher_item><item_number item_number_type="article_number">` ekler ve `<pages>`'i çıkarır; XSD'ye uygundur; çatal yok. |
| JATS | `<fpage>/<lpage>` yerine `<elocation-id>` yazar (JATS4R uyumlu). |
| Atıf | Paketle gelen CSL stillerinde stile duyarlı eşleme (ör. APA "Article e298"); bkz. *Bilinen davranışlar ve sınırlar*. |
| Göç | Sayfa alanına gömülü numaralardan `workNumber` doldurur: ayar panelinden (bu dergi) ya da komut satırı aracıyla (bir/tüm dergiler). Yalnızca türetir; `pages` asla yazılmaz. |
| Üreteç | İsteğe bağlı, dergi başına, varsayılan kapalı: sıradaki numarayı önerir. |

## Durum

OJS 3.3'te özellik tamam ve doğrulandı. Eklenti bağımsız bir yayın öncesi güvenlik ve
uyumluluk incelemesinden geçti (Kritik/Yüksek bulgu yok; PHP 7.4 / 8.1 temiz; çekirdek
dosyalarda değişiklik yok; dışa aktarım eklentisi çatalı yok). Test matrisi için
[docs/QA-MATRIX.md](docs/QA-MATRIX.md), tasarım gerekçesi için
[docs/TECHNICAL-NARRATIVE.md](docs/TECHNICAL-NARRATIVE.md) dosyalarına bakın.

## Göç

Makale numaralarını tarihsel olarak `pages` alanında tutan dergiler `workNumber`'ı
doldurabilir. İşlem bir **türetmedir, taşıma değildir**: kaynak `pages` değeri okunur ve
kopyalanır; **hiçbir kipte değiştirilmez**. Gerçek sayfa aralıkları (ör. `245-260`)
hiçbir zaman otomatik aday olmaz; belirsiz değerler elle inceleme için işaretlenir; geri
alma yalnızca aracın kendi türettiği değerleri siler (elle girilmiş Makale Numaralarına
dokunulmaz).

**Ayar panelinden (bu dergi):** eklentinin Ayarlar'ında **Tara** ile nelerin
dönüştürüleceğini önizleyin (salt okunur), ardından **Uygula** ile bu numaraları Makale
Numarası alanına kopyalayın ya da **Geri al** ile kaldırın. Uygula yalnızca bir taramadan
sonra etkinleşir ve onay ister. Tarama, elle kontrol gerektiren her değeri sonucun en
üstünde tam liste olarak gösterir (yayın no, sayı, makale başlığı, *Sayfalar* değeri ve
nedeni); ardından sayımlar gelir: dergideki makaleler (yayımlanmış ve diğer), toplam
yayın sürümü ve dönüştürülecekler. Yalnız her makalenin güncel sürümü dönüştürülür. Yalnız yayımlanmış ve zamanlanmış makaleler dönüştürülür; devam eden ya da reddedilen makaleler atlanır ve öyle gösterilir (makale yayımlandığında *Sayfalar* değeri bir makale numarasına benziyorsa numara kendiliğinden yazılır; değilse numarayı yayımdan önce Sayı formuna yazın).
**Tam sonucu indir (CSV)** makale başına bir satır verir: dönüştürülecek, elle bakılacak,
atlanan (gerçek sayfa aralığı, zaten numaralı, sayfa yok).

**Komut satırından (bir ya da tüm dergiler):**

```
php plugins/generic/articleNumber/tools/migrateArticleNumbers.php <dry-run|apply|rollback> [journalPath|all]
```

Büyük arşivlerde panel, aday sayısı eşiği (varsayılan 2000) aşılınca **Uygula**'yı kilitler
ve web isteği zaman aşımına tabi olmayan bu komuta yönlendirir. Paneldeki **Tara** ve
**Geri al** hiçbir zaman eşikle sınırlanmaz.

## Kurulum

1. Bu klasörü OJS 3.3 kurulumunuzda `plugins/generic/articleNumber/` altına kopyalayın.
2. OJS'te: **Ayarlar → Web Sitesi → Eklentiler → Genel Eklentiler**'de *Article Number
   Plugin*'i etkinleştirin.
3. Eklenti satırında **Ayarlar**'a tıklayın ve *Bu dergi için Makale Numarası alanını
   etkinleştir* kutusunu işaretleyin.

## Ayarlar

- **Makale Numarası alanını etkinleştir** — dergi başına ana anahtar (varsayılan kapalı).
- **Makale ayrıntı bloğunda göster** — varsayılan açık; etkin tema Makale Numarasını
  kendisi gösteriyorsa (Atlas, Nivo, Axis, JournalPlus ya da bunu bildiren bir tema) kapalı
  başlar, böylece numara iki kez görünmez; ayar penceresi bunu belirtir. Siz açar ya da
  kapatırsanız seçiminiz korunur. Yalnızca eklentinin sayfadaki öğesini etkiler; dışa
  aktarımı ve atıfı etkilemez.
- **Makale yayımlanırken numarayı sayfa değerinden türet** (varsayılan açık) — bir makale Makale Numarası olmadan yayımlandığında ya da zamanlandığında ve *Sayfalar* değeri bir makale numarasına benziyorsa (Tarama ile aynı kural: örneğin `e0231470`), değer alana kopyalanır. Gerçek sayfa aralıklarına ve belirsiz değerlere dokunulmaz, elle girilmiş numara hiçbir zaman ezilmez, *Sayfalar* değişmez. Her numarayı kendiniz yazmak için buradan kapatın.
- **Benzersizlik kapsamı** — *tüm dergi* (varsayılan, önerilen) her Makale Numarasının
  dergi genelinde benzersiz olmasını ister (sürekli yayın / PLoS modeli); *sayı başına*
  yalnızca aynı sayı içinde benzersizlik arar (cilt/sayı içinde numaralandıran dergiler için).
- **Makale Numarası Üreteci** (isteğe bağlı, varsayılan kapalı) — yeni, yayımlanmamış
  makaleler için sıradaki numarayı önerir.
- **Şablon** — öneri deseni: `{:04d}` sıfırla doldurulmuş sayaçtır (`e{:04d}` → e0001),
  `{n}` düz sayaçtır. Öneri alanı hiçbir zaman kısıtlamaz; editör istediği değeri
  yazabilir (ör. `P2.16`).

## Kullanım

1. Bir makalenin **Yayın → Sayı** sekmesinde, *Sayfalar*'ın altındaki alana Makale
   Numarasını yazın. *Sayfalar*'ı boş bırakın: makale numarası varken yetkili koordinat o
   olur; ikisi de doluysa Crossref, JATS ve Google Scholar Makale Numarasını kullanır; DOAJ, PubMed ve diğer dışa aktarımlar Sayfalar alanını kullanır.
2. Makale **yayımlandıktan** sonra Makale Numarası kilitlenir (yatırılmış bir koordinatı
   değiştirmek atıf/indeks eşleşmelerini bozar). Yayımlanmış makaleleri doldurmak için göç
   aracını kullanın.

## Tema entegrasyonu

Makale Numarası varsayılan olarak her temada gösterilir (ayrıntı/kenar çubuğu öğesi).
Temanızın **kendi yerinde**, örneğin sayfa aralığının yerine, göstermek için
`{article_number}` Smarty yardımcısını kullanın:

```smarty
{article_number publication=$publication assign="articleNumber"}
{if $articleNumber}Art. no. <b>{$articleNumber|escape}</b>
{elseif $publication->getData('pages')}p. <b>{$publication->getData('pages')|escape}</b>{/if}
```

Tam kılavuz için [docs/THEME-INTEGRATION.md](docs/THEME-INTEGRATION.md) dosyasına bakın.

## Bilinen davranışlar ve sınırlar

1. **IEEE "Art. no." etiketi** yalnızca sayfadaki **ana atıfa** uygulanır. AJAX stil
   değiştirici ve indirilen atıf CSL `number`/`page` eşlemesini yansıtır ama etiket
   yeniden yazımını yansıtmaz (OJS, çizim sonrası atıf kancası sunmaz).
2. **DOAJ ve DataCite** dışa aktarımlarında ayrı bir makale numarası/elocation alanı
   yoktur; değer bilinçli olarak hiçbir sayfa alanına **zorla yazılmaz**. Belgelenmiş bir
   sınırdır; veri bozulmaz.
3. **JATS köprüsü** bir JATS üreticisi gerektirir (ör. `jatsTemplate` eklentisi ya da bir
   FullTextCreator). Kanca standarttır (`OAIMetadataFormat_JATS::findJats`); üretici yoksa
   düzeltilecek JATS de yoktur.
4. **Üreteç sıradaki numarayı alana doldurmaz**, alanın yardım metninde **önerir**; çünkü
   OJS alanın reaktif değerini yayından yeniden eşitler. Öneri serbest bir metin kalır.
5. **Yayımlanmış koordinatın değişmezliği:** form, yayımlanmış bir makalenin Makale
   Numarasının değiştirilmesini ya da eklenmesini engeller. Eski yayımlanmış içeriği doğru
   alana taşımak için (yalnızca `pages` gerçekten vekil bir numara taşıyorsa) formu değil
   göç aracını kullanın. Araç ne değeri ne de `pages`'i değiştirir ve Crossref'e **otomatik
   yeniden yatırma yapmaz**.
6. **Göç aracı bir türetmedir:** `pages` her kipte salt okunurdur; gerçek sayfa aralıkları
   (ör. `245-260`) hiçbir zaman otomatik aday olmaz; geri alma yalnızca aracın kendi
   türettiği değerleri siler.
7. **"Makale ayrıntı bloğunda göster"** ayarı: teması Makale Numarasını kendisi basan
   dergiler (Nivo/Atlas/Axis) çift gösterimi önlemek için bunu kapatabilir (aksi halde numara
   iki kez görünür). Yalnızca eklentinin sayfadaki kartını etkiler; dışa aktarımı, atıfı ya
   da Google Scholar düzeltmesini **etkilemez**.
8. **ABNT, Harvard ve IEEE Makale Numarasını "p." (sayfa) diye etiketler**, örn. "p. e0500".
   Atıf eşlemesi stile duyarlıdır (1.5.0'dan beri): `article-journal` için CSL `number`
   değişkenini basan stiller (MLA) yalnızca `number` alır, APA "Article e…" etiketini korur.
   Ancak ABNT/Harvard/IEEE dergi makalelerinde `number`'ı hiç basmaz ve `page` yuvasına "p."
   etiketi/öneki dayatır; numara **görünür ve doğrudur** ama sayfa etiketiyle gösterilir.
   Kaldırmak, paketle gelen CSL dosyalarını çatallamayı gerektirir; eklenti bunu yapmaz.
9. **RIS ve BibTeX indirmeleri Makale Numarasını sayfa alanında taşır**, makinece okunur
   kanonik bir alanda değil (RIS → `SP`, BibTeX → `pages`). Değer mevcut ve doğrudur,
   saklanan `pages` alanı sızmaz. BibTeX'in kanonik `eid` alanına yazmak paketle gelen
   `bibtex.csl`'i çatallamayı gerektirir (yapılmadı). Kanonik, standartlara uygun makale
   numarası metaverisi Crossref (`item_number`) ve JATS (`elocation-id`) ile iletilir.
10. **Native XML dışa ve içe aktarım Makale Numarasını taşımaz.** Dışa aktarılıp başka bir
    dergiye içe aktarılan makale numarasız gelir; *Sayfalar* değeri gelir.
11. **DOAJ, PubMed ve TR Dizin dışa aktarımları** Makale Numarasını değil *Sayfalar*
    alanını okur. (Crossref numarayı okur; bkz. yukarısı.)
12. **OAI-PMH:** paketle gelen hiçbir biçim (`oai_dc`, `oai_marc`, `marcxml`, `rfc1807`)
    numarayı taşımaz. Numara yalnızca bir JATS biçiminde `elocation-id` olarak çıkar ve
    yalnızca bir JATS üreticisi kuruluysa (madde 3).

## Lisans

GNU GPL v3. Bkz. `LICENSE`.
