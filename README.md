# Astro Envato Template Integration

Bu proje, Envato HTML template'lerini Astro framework'ü ile entegre etmek için oluşturulmuş bir starter kit'tir.

## 🚀 Proje Yapısı

```text
/
├── public/
│   ├── favicon.svg
│   └── (Envato template assets buraya)
├── src/
│   ├── components/
│   │   └── TemplateComponent.astro
│   ├── layouts/
│   │   └── BaseLayout.astro
│   ├── pages/
│   │   └── index.astro
│   └── styles/
│       └── global.css
├── astro.config.mjs
├── package.json
└── tsconfig.json
```

## 🛠️ Kurulum ve Kullanım

### Geliştirme Sunucusunu Başlatma

```bash
npm run dev
```

Bu komut development server'ı `http://localhost:3000` adresinde başlatır.

### Production Build

```bash
npm run build
```

Build edilen dosyalar `./dist/` klasöründe oluşturulur.

### Preview

```bash
npm run preview
```

Production build'inin önizlemesini görüntüler.

## 📋 Envato Template Entegrasyonu Adımları

### 1. Template Dosyalarını Kopyalama

Envato template'inizin dosyalarını şu şekilde organize edin:

```text
public/
├── css/
│   ├── bootstrap.min.css
│   ├── style.css
│   └── (diğer CSS dosyaları)
├── js/
│   ├── jquery.min.js
│   ├── bootstrap.min.js
│   └── (diğer JS dosyaları)
├── images/
│   └── (görsel dosyaları)
└── fonts/
    └── (font dosyaları)
```

### 2. Layout'u Güncelleme

`src/layouts/BaseLayout.astro` dosyasında template'inizin CSS ve JS dosyalarını ekleyin:

```html
<head>
  <!-- Template CSS -->
  <link rel="stylesheet" href="/css/bootstrap.min.css" />
  <link rel="stylesheet" href="/css/style.css" />
</head>

<body>
  <slot />
  
  <!-- Template JS -->
  <script src="/js/jquery.min.js"></script>
  <script src="/js/bootstrap.min.js"></script>
</body>
```

### 3. Sayfaları Oluşturma

Template'inizin HTML sayfalarını `src/pages/` klasöründe Astro dosyaları olarak oluşturun:

```astro
---
import BaseLayout from '../layouts/BaseLayout.astro';
---

<BaseLayout title="Ana Sayfa">
  <!-- Template HTML içeriği buraya -->
</BaseLayout>
```

### 4. Component'leri Ayırma

Tekrar kullanılabilir parçaları `src/components/` klasöründe ayrı component'ler halinde organize edin:

- Header/Navigation
- Footer
- Hero Section
- Card Components
- Form Components

### 5. Stil Yönetimi

Global stiller için `src/styles/global.css` dosyasını kullanın. Component-specific stiller için her Astro component'inin kendi `<style>` bölümünü kullanabilirsiniz.

## ⚡ Özellikler

- ✅ Astro framework ile static site generation
- ✅ TypeScript desteği
- ✅ Path aliases (@/ shortcuts)
- ✅ Responsive design ready
- ✅ SEO friendly structure
- ✅ Development server hot reload
- ✅ Production optimize build

## 📚 Faydalı Linkler

- [Astro Documentation](https://docs.astro.build)
- [Astro Components](https://docs.astro.build/en/core-concepts/astro-components/)
- [Astro Layouts](https://docs.astro.build/en/core-concepts/layouts/)

## 💡 İpuçları

1. **Responsive Design**: Template'inizin responsive özelliklerini korumak için viewport meta tag'ını unutmayın.

2. **Performance**: Sadece kullandığınız CSS/JS dosyalarını include edin.

3. **SEO**: Her sayfa için uygun title ve meta description ekleyin.

4. **Images**: Görselleri WebP formatına çevirerek performansı artırabilirsiniz.

5. **Code Splitting**: Büyük template'ler için sayfaları küçük component'lere bölün.

## 🔧 Geliştirme Komutları

| Komut | Açıklama |
|-------|----------|
| `npm run dev` | Development server başlat |
| `npm run build` | Production build oluştur |
| `npm run preview` | Build önizlemesini göster |
| `npm run astro` | Astro CLI komutları |

---

**Not**: Bu proje Astro 4.0+ versiyonu ile uyumludur. Template entegrasyonu sırasında sorun yaşarsanız, template'inizin jQuery/Bootstrap versiyonları ile uyumluluk kontrolü yapın.