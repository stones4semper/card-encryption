import 'react-native-get-random-values'; 
import React, {useState} from 'react';
import { View, Text, TextInput, TouchableOpacity, ActivityIndicator, StyleSheet, Alert} from 'react-native';
import sodium from 'libsodium-wrappers';
import { SafeAreaView, SafeAreaProvider } from 'react-native-safe-area-context';

const baseURL = 'http://10.152.95.117/card-encryption/api';
const KEY_NEW = `${baseURL}/key_new.php`;
const SUBMIT = `${baseURL}/submit.php`;

export default function App(){
    const [name,setName]=useState('');
    const [pan,setPan]=useState('');
    const [exp,setExp]=useState('');
    const [cvv,setCvv]=useState('');
    const [loading,setLoading]=useState(false);

    const onSend = async () => {
        try {
            setLoading(true);
            await sodium.ready;
            const r = await fetch(KEY_NEW);
            // console.log(await r.json())
            if (!r.ok) throw new Error('key fetch failed');
            const { key_id, public_key_b64 } = await r.json();
            const pub = sodium.from_base64(public_key_b64, sodium.base64_variants.ORIGINAL);
            const payload = JSON.stringify({ card: { name, pan, exp, cvv }, ts: Date.now() });
            const sealed = sodium.crypto_box_seal(payload, pub);
            const payload_b64 = sodium.to_base64(sealed, sodium.base64_variants.ORIGINAL);
            const resp = await fetch(SUBMIT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key_id, payload: payload_b64 })
            });
            if (!resp.ok) throw new Error('submit failed');
			console.log(await resp.json())
            Alert.alert('Success', 'Encrypted and sent');
        } catch (err) {
            Alert.alert('Error', err.message);
        } finally {
            setLoading(false);
        }
    };

    return(
        <SafeAreaProvider style={{flex: 1}}>
            <SafeAreaView style={s.container}>
                <View style={s.card}>
                    <Text style={s.h1}>Secure Card Entry</Text>
                    <TextInput style={s.input} placeholder="Name on card" placeholderTextColor="#9aa0a6" value={name} onChangeText={setName}/>
                    <TextInput style={s.input} placeholder="Card number" placeholderTextColor="#9aa0a6" keyboardType="number-pad" value={pan} onChangeText={t=>setPan(t.replace(/[^\d]/g,''))} maxLength={19}/>
                    <View style={s.row}>
                        <TextInput style={[s.input,s.half]} placeholder="MM/YY" placeholderTextColor="#9aa0a6" keyboardType="number-pad" value={exp} onChangeText={t=>{const v=t.replace(/[^\d]/g,'').slice(0,4); setExp(v.length>2?v.slice(0,2)+'/'+v.slice(2):v)}} maxLength={5}/>
                        <TextInput style={[s.input,s.half]} placeholder="CVV" placeholderTextColor="#9aa0a6" keyboardType="number-pad" value={cvv} onChangeText={t=>setCvv(t.replace(/[^\d]/g,'').slice(0,4))} maxLength={4} secureTextEntry/>
                    </View>
                    <TouchableOpacity style={s.btn} onPress={onSend} disabled={loading}>
                        {loading?<ActivityIndicator color="#fff"/>:<Text style={s.btnText}>Send</Text>}
                    </TouchableOpacity>
                    <Text style={s.note}>Key is created on click and expires after one use</Text>
                </View>
            </SafeAreaView>
        </SafeAreaProvider>
    );
}

const s=StyleSheet.create({
    container:{flex:1,backgroundColor:'#0b0f17',alignItems:'center',justifyContent:'center',padding:16},
    card:{width:'100%',maxWidth:420,backgroundColor:'#121826',borderRadius:16,padding:20},
    h1:{color:'#e6edf3',fontSize:20,fontWeight:'700',marginBottom:16},
    input:{backgroundColor:'#0f1523',color:'#e6edf3',borderRadius:12,paddingHorizontal:14,paddingVertical:12,marginBottom:12,borderWidth:1,borderColor:'#1f2a44'},
    row:{flexDirection:'row',gap:12},
    half:{flex:1},
    btn:{backgroundColor:'#2563eb',borderRadius:12,alignItems:'center',justifyContent:'center',paddingVertical:14,marginTop:6},
    btnText:{color:'#fff',fontSize:16,fontWeight:'700'},
    note:{color:'#9aa0a6',fontSize:12,marginTop:10,textAlign:'center'}
});
