//importScriptExt('wikificator.js')
//importScriptExt('WikEd.js')
 
//Toolbar buttons
 
function StandardButtons(){
 if (mwEditButtons.length < 6) return
 mwEditButtons[0].imageFile = wgScriptPath+'/extensions/CustisScripts/images/Button_boldru.png'
 mwEditButtons[1].imageFile = wgScriptPath+'/extensions/CustisScripts/images/Button_italicru.png'
 mwEditButtons[2].imageFile = wgScriptPath+'/extensions/CustisScripts/images/Button_internal_link_ukr.png'
 mwEditButtons[5].tagClose = '|thumb]]'
} 
 
 
function CustomButtons(){
 addCustomButton(wgScriptPath+'/extensions/CustisScripts/images/Button_redirect_rus.png', 'Перенаправление','#REDIRECT [[',']]','название страницы')
 addCustomButton(wgScriptPath+'/extensions/CustisScripts/images/Button-cat.png','Категория','[\[Категория:',']]\n','')
 addCustomButton(wgScriptPath+'/extensions/CustisScripts/images/Button_hide_comment.png', 'Комментарий', '<!-- ', ' -->', 'Комментарий')
 addCustomButton(wgScriptPath+'/extensions/CustisScripts/images/Button_blockquote.png', 'Развёрнутая цитата', '<blockquote>\n', '\n</blockquote>', 'Развёрнутая цитата одним абзацем')
 addCustomButton(wgScriptPath+'/extensions/CustisScripts/images/Button_insert_table.png',
 'Вставить таблицу', '{| class="wikitable"\n|-\n', '\n|}', '! заголовок 1\n! заголовок 2\n! заголовок 3\n|-\n| строка 1, ячейка 1\n| строка 1, ячейка 2\n| строка 1, ячейка 3\n|-\n| строка 2, ячейка 1\n| строка 2, ячейка 2\n| строка 2, ячейка 3')
 addCustomButton(wgScriptPath+'/extensions/CustisScripts/images/Button_reflink.png','Сноска','<ref>','</ref>','')
}
 
function addCustomButton(img, tip, open, close, sample){
 mwCustomEditButtons[mwCustomEditButtons.length] =
  {'imageFile':img, 'speedTip':tip, 'tagOpen':open, 'tagClose':close, 'sampleText':sample}
}
 
 
function addFuncButton(img, tip, func){
 var toolbar = document.getElementById('toolbar')
 if (!toolbar) return
 var i = document.createElement('img')
 i.src = img
 i.alt = tip;  i.title = tip
 i.onclick = func
 i.style.cursor = 'pointer'
 toolbar.appendChild(i)
}
 
 
function WikifButton(){
 var t = document.getElementById('wpTextbox1')
 if (!t || (!document.selection && t.selectionStart == null)) return
 addFuncButton(wgScriptPath+'/extensions/CustisScripts/images/Button-wikifikator.png', 'Викификатор', Wikify)
// addFuncButton(wgScriptPath+'/extensions/CustisScripts/images/Button-wikifikator.png', 'Викификатор', WikEdWikifyRus)
}
 
//Edit Summary buttons 
 
function SummaryButtons(){
 var wpSummary = document.getElementById('wpSummary')
 if (!wpSummary || (wpSummary.form.wpSection && wpSummary.form.wpSection.value == 'new')) return
 wpSummaryBtn = document.createElement('span') //global var
 wpSummaryBtn.id = 'userSummaryButtonsA'
 wpSummary.parentNode.insertBefore(wpSummaryBtn, wpSummary.nextSibling)
 wpSummary.parentNode.insertBefore(document.createElement('br'), wpSummary.nextSibling)
 addSumButton('оформл.', 'оформление', 'Улучшено оформление')
 addSumButton('стиль', 'стилевые правки', 'Поправлен стиль изложения')
 addSumButton('орфогр./пункт.', 'орфография/пунктуация', 'Поправлена орфография и пунктуация')
 addSumButton('катег.', 'категория', 'Исправлена категоризация')
 addSumButton('дополн.', 'дополнение', 'Добавлены новые сведения')
 addSumButton('замеч.', 'замечание', 'Внесено существенное замечание')
 addSumButton('обнов.', 'обновление данных', 'Обновлены устаревшие данные')
}
 
function addSumButton(name, text, title) {
 var btn = document.createElement('a')
 btn.appendChild(document.createTextNode(name))
 btn.title = title
 btn.onclick = function(){insertSummary(text)}
 wpSummaryBtn.appendChild(btn)
}
 
function insertSummary(text) {
 var wpSummary = document.getElementById('wpSummary')
 if (wpSummary.value.indexOf(text) != -1) return 
 if (wpSummary.value.match(/[^,; \/]$/)) wpSummary.value += ','
 if (wpSummary.value.match(/[^ ]$/)) wpSummary.value += ' '
 wpSummary.value += text
}
 
 
//call functions
addOnloadHook(StandardButtons)
addOnloadHook(CustomButtons)
addOnloadHook(WikifButton)
addOnloadHook(SummaryButtons)
 
  
