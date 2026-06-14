class AssemblyAIPcm16Processor extends AudioWorkletProcessor {
  process(inputs) {
    const input = inputs[0] && inputs[0][0];
    if (input && input.length) {
      const pcm16 = new Int16Array(input.length);
      for (let index = 0; index < input.length; index += 1) {
        const sample = Math.max(-1, Math.min(1, input[index]));
        pcm16[index] = sample < 0
          ? Math.round(sample * 32768)
          : Math.round(sample * 32767);
      }
      this.port.postMessage(pcm16.buffer, [pcm16.buffer]);
    }

    return true;
  }
}

registerProcessor('assemblyai-pcm16-processor', AssemblyAIPcm16Processor);
