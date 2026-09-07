<?php

declare(strict_types=1);

namespace SesamePortal;

final class VideoWallTranslations
{
    public static function messages(): array
    {
        $keys = [
            'nav.mosaic', 'settings.mosaicSaved', 'wall.title', 'wall.new', 'wall.edit', 'wall.empty',
            'wall.name', 'wall.rows', 'wall.columns', 'wall.owner', 'wall.cameras', 'wall.selection',
            'wall.unavailable', 'wall.invalidName', 'wall.invalidGrid', 'wall.invalidSelection',
            'wall.cameraUnavailable', 'wall.notFound', 'wall.saved', 'wall.deleteConfirm',
            'wall.start', 'wall.stop', 'wall.fullscreen', 'wall.earlier', 'wall.later', 'wall.remove', 'wall.open', 'wall.emptySlot',
        ];
        $rows = [
            'ru' => [
                'Список', 'Настройки списка сохранены', 'Видеостены', 'Новая видеостена', 'Изменить видеостену', 'Видеостен пока нет',
                'Название', 'Строки', 'Колонки', 'Владелец', 'Камеры', 'Порядок камер',
                'Камера недоступна', 'Укажите название от 1 до 255 символов', 'Количество строк и колонок должно быть от 1 до 6',
                'Выберите хотя бы одну камеру, без повторов. Количество камер не должно превышать число ячеек',
                'Одна или несколько камер недоступны вам или владельцу видеостены', 'Видеостена не найдена', 'Видеостена сохранена', 'Подтверждаю удаление видеостены',
                'Запустить', 'Остановить', 'На весь экран', 'Переместить раньше', 'Переместить позже', 'Убрать камеру', 'Открыть плеер', 'Пустая ячейка',
            ],
            'en' => [
                'List', 'List settings saved', 'Video walls', 'New video wall', 'Edit video wall', 'No video walls yet',
                'Name', 'Rows', 'Columns', 'Owner', 'Cameras', 'Camera order',
                'Camera unavailable', 'Enter a name between 1 and 255 characters', 'Rows and columns must be between 1 and 6',
                'Select at least one camera, without duplicates. The camera count must not exceed the number of cells',
                'One or more cameras are unavailable to you or the video wall owner', 'Video wall not found', 'Video wall saved', 'I confirm deletion of this video wall',
                'Start', 'Stop', 'Full screen', 'Move earlier', 'Move later', 'Remove camera', 'Open player', 'Empty cell',
            ],
            'de' => [
                'Liste', 'Listeneinstellungen gespeichert', 'Videowände', 'Neue Videowand', 'Videowand bearbeiten', 'Noch keine Videowände',
                'Name', 'Zeilen', 'Spalten', 'Besitzer', 'Kameras', 'Kamerareihenfolge',
                'Kamera nicht verfügbar', 'Geben Sie einen Namen mit 1 bis 255 Zeichen ein', 'Zeilen und Spalten müssen zwischen 1 und 6 liegen',
                'Wählen Sie mindestens eine Kamera ohne Duplikate. Die Kameraanzahl darf die Zellenanzahl nicht überschreiten',
                'Eine oder mehrere Kameras sind für Sie oder den Besitzer nicht verfügbar', 'Videowand nicht gefunden', 'Videowand gespeichert', 'Ich bestätige das Löschen dieser Videowand',
                'Starten', 'Stoppen', 'Vollbild', 'Nach vorne verschieben', 'Nach hinten verschieben', 'Kamera entfernen', 'Player öffnen', 'Leere Zelle',
            ],
            'fr' => [
                'Liste', 'Paramètres de la liste enregistrés', 'Murs vidéo', 'Nouveau mur vidéo', 'Modifier le mur vidéo', 'Aucun mur vidéo pour le moment',
                'Nom', 'Lignes', 'Colonnes', 'Propriétaire', 'Caméras', 'Ordre des caméras',
                'Caméra indisponible', 'Saisissez un nom de 1 à 255 caractères', 'Le nombre de lignes et de colonnes doit être compris entre 1 et 6',
                'Sélectionnez au moins une caméra, sans doublons. Le nombre de caméras ne doit pas dépasser le nombre de cases',
                'Une ou plusieurs caméras sont indisponibles pour vous ou le propriétaire du mur vidéo', 'Mur vidéo introuvable', 'Mur vidéo enregistré', 'Je confirme la suppression de ce mur vidéo',
                'Démarrer', 'Arrêter', 'Plein écran', 'Déplacer avant', 'Déplacer après', 'Retirer la caméra', 'Ouvrir le lecteur', 'Case vide',
            ],
            'es' => [
                'Lista', 'Configuración de la lista guardada', 'Muros de vídeo', 'Nuevo muro de vídeo', 'Editar muro de vídeo', 'Todavía no hay muros de vídeo',
                'Nombre', 'Filas', 'Columnas', 'Propietario', 'Cámaras', 'Orden de cámaras',
                'Cámara no disponible', 'Introduzca un nombre de entre 1 y 255 caracteres', 'Las filas y columnas deben estar entre 1 y 6',
                'Seleccione al menos una cámara, sin duplicados. El número de cámaras no debe superar el número de celdas',
                'Una o más cámaras no están disponibles para usted o el propietario del muro', 'Muro de vídeo no encontrado', 'Muro de vídeo guardado', 'Confirmo la eliminación de este muro de vídeo',
                'Iniciar', 'Detener', 'Pantalla completa', 'Mover antes', 'Mover después', 'Quitar cámara', 'Abrir reproductor', 'Celda vacía',
            ],
            'it' => [
                'Elenco', 'Impostazioni elenco salvate', 'Videopareti', 'Nuova videoparete', 'Modifica videoparete', 'Nessuna videoparete presente',
                'Nome', 'Righe', 'Colonne', 'Proprietario', 'Telecamere', 'Ordine delle telecamere',
                'Telecamera non disponibile', 'Inserisci un nome da 1 a 255 caratteri', 'Righe e colonne devono essere comprese tra 1 e 6',
                'Seleziona almeno una telecamera, senza duplicati. Il numero di telecamere non deve superare il numero di celle',
                'Una o più telecamere non sono disponibili per te o per il proprietario della videoparete', 'Videoparete non trovata', 'Videoparete salvata', 'Confermo la cancellazione di questa videoparete',
                'Avvia', 'Ferma', 'Schermo intero', 'Sposta prima', 'Sposta dopo', 'Rimuovi telecamera', 'Apri lettore', 'Cella vuota',
            ],
            'pt' => [
                'Lista', 'Configurações da lista guardadas', 'Videowalls', 'Novo videowall', 'Editar videowall', 'Ainda não existem videowalls',
                'Nome', 'Linhas', 'Colunas', 'Proprietário', 'Câmaras', 'Ordem das câmaras',
                'Câmara indisponível', 'Introduza um nome entre 1 e 255 caracteres', 'As linhas e colunas devem estar entre 1 e 6',
                'Selecione pelo menos uma câmara, sem duplicados. O número de câmaras não pode exceder o número de células',
                'Uma ou mais câmaras estão indisponíveis para si ou para o proprietário do videowall', 'Videowall não encontrado', 'Videowall guardado', 'Confirmo a eliminação deste videowall',
                'Iniciar', 'Parar', 'Ecrã inteiro', 'Mover antes', 'Mover depois', 'Remover câmara', 'Abrir leitor', 'Célula vazia',
            ],
            'bg' => [
                'Списък', 'Настройките на списъка са запазени', 'Видеостени', 'Нова видеостена', 'Редактиране на видеостена', 'Все още няма видеостени',
                'Име', 'Редове', 'Колони', 'Собственик', 'Камери', 'Ред на камерите',
                'Камерата е недостъпна', 'Въведете име от 1 до 255 знака', 'Броят редове и колони трябва да е между 1 и 6',
                'Изберете поне една камера, без повторения. Броят камери не трябва да надвишава броя клетки',
                'Една или повече камери са недостъпни за вас или за собственика на видеостената', 'Видеостената не е намерена', 'Видеостената е запазена', 'Потвърждавам изтриването на тази видеостена',
                'Стартиране', 'Спиране', 'Цял екран', 'Премести напред', 'Премести назад', 'Премахни камера', 'Отвори плейъра', 'Празна клетка',
            ],
            'pl' => [
                'Lista', 'Zapisano ustawienia listy', 'Ściany wideo', 'Nowa ściana wideo', 'Edytuj ścianę wideo', 'Brak ścian wideo',
                'Nazwa', 'Wiersze', 'Kolumny', 'Właściciel', 'Kamery', 'Kolejność kamer',
                'Kamera niedostępna', 'Wpisz nazwę o długości od 1 do 255 znaków', 'Liczba wierszy i kolumn musi wynosić od 1 do 6',
                'Wybierz co najmniej jedną kamerę, bez duplikatów. Liczba kamer nie może przekraczać liczby komórek',
                'Co najmniej jedna kamera jest niedostępna dla ciebie lub właściciela ściany wideo', 'Nie znaleziono ściany wideo', 'Zapisano ścianę wideo', 'Potwierdzam usunięcie tej ściany wideo',
                'Uruchom', 'Zatrzymaj', 'Pełny ekran', 'Przenieś wcześniej', 'Przenieś później', 'Usuń kamerę', 'Otwórz odtwarzacz', 'Pusta komórka',
            ],
            'zh' => [
                '列表', '列表设置已保存', '视频墙', '新建视频墙', '编辑视频墙', '暂无视频墙',
                '名称', '行数', '列数', '所有者', '摄像机', '摄像机顺序',
                '摄像机不可用', '请输入1至255个字符的名称', '行数和列数必须介于1和6之间',
                '请至少选择一台摄像机，不可重复。摄像机数量不能超过单元格数量',
                '您或视频墙所有者无法访问一台或多台摄像机', '未找到视频墙', '视频墙已保存', '我确认删除此视频墙',
                '开始', '停止', '全屏', '向前移动', '向后移动', '移除摄像机', '打开播放器', '空单元格',
            ],
            'ja' => [
                '一覧', '一覧の設定を保存しました', 'ビデオウォール', '新規ビデオウォール', 'ビデオウォールを編集', 'ビデオウォールはまだありません',
                '名前', '行数', '列数', '所有者', 'カメラ', 'カメラの順序',
                'カメラを利用できません', '1～255文字の名前を入力してください', '行数と列数は1～6にしてください',
                '重複なしで1台以上のカメラを選択してください。カメラ数はセル数以下にしてください',
                'あなたまたはビデオウォールの所有者が利用できないカメラが含まれています', 'ビデオウォールが見つかりません', 'ビデオウォールを保存しました', 'このビデオウォールの削除を確認します',
                '開始', '停止', '全画面', '前へ移動', '後ろへ移動', 'カメラを削除', 'プレーヤーを開く', '空のセル',
            ],
            'ko' => [
                '목록', '목록 설정이 저장되었습니다', '비디오 월', '새 비디오 월', '비디오 월 편집', '아직 비디오 월이 없습니다',
                '이름', '행', '열', '소유자', '카메라', '카메라 순서',
                '카메라를 사용할 수 없습니다', '1~255자의 이름을 입력하세요', '행과 열은 1~6이어야 합니다',
                '중복 없이 카메라를 하나 이상 선택하세요. 카메라 수는 셀 수를 초과할 수 없습니다',
                '사용자 또는 비디오 월 소유자가 접근할 수 없는 카메라가 있습니다', '비디오 월을 찾을 수 없습니다', '비디오 월이 저장되었습니다', '이 비디오 월의 삭제를 확인합니다',
                '시작', '중지', '전체 화면', '앞으로 이동', '뒤로 이동', '카메라 제거', '플레이어 열기', '빈 셀',
            ],
            'ar' => [
                'القائمة', 'تم حفظ إعدادات القائمة', 'جدران الفيديو', 'جدار فيديو جديد', 'تعديل جدار الفيديو', 'لا توجد جدران فيديو بعد',
                'الاسم', 'الصفوف', 'الأعمدة', 'المالك', 'الكاميرات', 'ترتيب الكاميرات',
                'الكاميرا غير متاحة', 'أدخل اسماً من 1 إلى 255 حرفاً', 'يجب أن يكون عدد الصفوف والأعمدة بين 1 و6',
                'اختر كاميرا واحدة على الأقل دون تكرار. يجب ألا يتجاوز عدد الكاميرات عدد الخلايا',
                'كاميرا واحدة أو أكثر غير متاحة لك أو لمالك جدار الفيديو', 'لم يتم العثور على جدار الفيديو', 'تم حفظ جدار الفيديو', 'أؤكد حذف جدار الفيديو هذا',
                'تشغيل', 'إيقاف', 'ملء الشاشة', 'نقل إلى موضع سابق', 'نقل إلى موضع لاحق', 'إزالة الكاميرا', 'فتح المشغل', 'خلية فارغة',
            ],
            'hy' => [
                'Ցանկ', 'Ցանկի կարգավորումները պահպանված են', 'Տեսապատեր', 'Նոր տեսապատ', 'Խմբագրել տեսապատը', 'Դեռ տեսապատեր չկան',
                'Անուն', 'Տողեր', 'Սյունակներ', 'Սեփականատեր', 'Տեսախցիկներ', 'Տեսախցիկների հերթականություն',
                'Տեսախցիկն անհասանելի է', 'Մուտքագրեք 1-ից 255 նիշ պարունակող անուն', 'Տողերի և սյունակների քանակը պետք է լինի 1-ից 6',
                'Ընտրեք առնվազն մեկ տեսախցիկ՝ առանց կրկնությունների։ Քանակը չպետք է գերազանցի բջիջների քանակը',
                'Մեկ կամ մի քանի տեսախցիկ անհասանելի է ձեզ կամ տեսապատի սեփականատիրոջը', 'Տեսապատը չի գտնվել', 'Տեսապատը պահպանված է', 'Հաստատում եմ այս տեսապատի ջնջումը',
                'Գործարկել', 'Կանգնեցնել', 'Ամբողջ էկրանով', 'Տեղափոխել առաջ', 'Տեղափոխել հետ', 'Հեռացնել տեսախցիկը', 'Բացել նվագարկիչը', 'Դատարկ բջիջ',
            ],
        ];
        $messages = [];
        foreach ($rows as $locale => $values) {
            $messages[$locale] = array_combine($keys, $values);
        }
        return $messages;
    }
}
